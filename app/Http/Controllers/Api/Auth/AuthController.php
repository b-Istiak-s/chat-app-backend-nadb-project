<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\StartRequest;
use App\Http\Requests\Api\Auth\VerifyOtpRequest;
use App\Models\User;
use App\Services\BdApps\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single-user-type auth flow:
 *   - start(): find-or-create user by phone.
 *     * If the user is `verified` (subscription_status='pending'),
 *       issue a Sanctum token directly (no OTP, no password) and
 *       fire a courtesy SMS via BDApps /sms/send so the user
 *       sees a record of the login.
 *     * Otherwise trigger the standard BDApps OTP flow and
 *       return `{token: null, requires_otp: true, reference_no}`.
 *   - verify(): confirm OTP with BDApps, flip user+row from
 *     `unverified` → `pending`, and issue a Sanctum token. The
 *     row's `bdapps_subscription_status` mirror carries the
 *     gateway's literal reply; the UI reads it via `/auth/me`
 *     and surfaces the "Payment pending" page when the mirror
 *     is non-`REGISTERED`.
 *   - me(): return phone, user `subscription_status`, and the
 *     computed `has_pending_charge` flag the app uses to switch
 *     between chat and the "Payment pending" page.
 *   - logout(): revoke current Sanctum token.
 *   - unsubscribe(): cancel BDApps subscription; user + row
 *     move to `cancelled`.
 */
class AuthController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptionService,
    ) {}

    public function start(StartRequest $request): JsonResponse
    {
        try {
            $phone = $request->input('phone');
            $user = User::firstOrCreate(
                ['phone' => $phone],
                ['subscription_status' => 'unverified'],
            );

            // Verified users (`subscription_status === 'pending'`)
            // already have a Sanctum token-issuing state; skip the
            // OTP step entirely. We still acknowledge the login via
            // /sms/send so they see a record on their phone.
            if ($this->userHasValidToken($user)) {
                $token = $user->createToken('mobile')->plainTextToken;

                $this->subscriptionService->notifyLogin($user);

                return $this->sendSuccessResponse([
                    'token' => $token,
                    'requires_otp' => false,
                    'reference_no' => null,
                    'subscription_status' => $user->subscription_status,
                    'has_pending_charge' => $user->hasPendingCharge(),
                ], 'Logged in.');
            }

            $result = $this->subscriptionService->startSubscription($user);

            return $this->sendSuccessResponse([
                'token' => null,
                'requires_otp' => (bool) ($result['reference_no'] ?? null),
                'reference_no' => $result['reference_no'] ?? null,
            ], 'OTP requested.');
        } catch (\Throwable $e) {
            return $this->handleError($e);
        }
    }

    public function verify(VerifyOtpRequest $request): JsonResponse
    {
        try {
            $phone = $request->input('phone');
            $otp = $request->input('otp');
            $user = User::where('phone', $phone)->first();

            if (! $user) {
                return $this->sendErrorResponse('User not found. Please start again.', Response::HTTP_NOT_FOUND);
            }

            $result = $this->subscriptionService->verifyOtp($user, $otp);

            if (! ($result['ok'] ?? false)) {
                return $this->sendErrorResponse(
                    $result['status_detail'] ?? 'Invalid or expired OTP.',
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    ['error_code' => $result['status_code'] ?? null],
                );
            }

            // Token-issuance point: the user has just been
            // verified (subscription_status = 'pending'). Issue
            // the Sanctum token regardless of whether BDApps has
            // confirmed REGISTERED — the row mirror carries that
            // verdict; the UI reads it via /auth/me.
            $token = $user->createToken('mobile')->plainTextToken;

            return $this->sendSuccessResponse([
                'token' => $token,
                'subscription_status' => $user->subscription_status,
                'has_pending_charge' => $user->hasPendingCharge(),
            ], 'Phone verified successfully.');
        } catch (\Throwable $e) {
            return $this->handleError($e);
        }
    }

    public function me(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            return $this->sendSuccessResponse([
                'id' => $user->id,
                'phone' => $user->phone,
                'subscription_status' => $user->subscription_status,
                'has_pending_charge' => $user->hasPendingCharge(),
                'subscribed_at' => $user->subscribed_at,
            ]);
        } catch (\Throwable $e) {
            return $this->handleError($e);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $request->user()->currentAccessToken()->delete();

            return $this->sendSuccessResponse(null, 'Logged out successfully.');
        } catch (\Throwable $e) {
            return $this->handleError($e);
        }
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $subscription = $this->subscriptionService->cancelSubscription($user);

            return $this->sendSuccessResponse([
                'subscription_status' => $subscription->status,
                'is_subscribed' => false,
            ], 'Subscription cancelled.');
        } catch (\Throwable $e) {
            return $this->handleError($e);
        }
    }

    /**
     * True when the user can skip the OTP step entirely. In the
     * new state model that's simply "the user is verified" —
     * i.e. `subscription_status === 'pending'`. The
     * `unverified` and `cancelled` users both go through a fresh
     * OTP on `/api/auth/start`.
     */
    private function userHasValidToken(User $user): bool
    {
        return $user->isVerified();
    }
}
