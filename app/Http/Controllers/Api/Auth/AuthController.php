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
 *     * If the user is `token-bearing` (subscription_status is
 *       `pending` OR `registered`), issue a Sanctum token
 *       directly (no OTP, no password) and fire a courtesy SMS
 *       via BDApps /sms/send so the user sees a record of the
 *       login.
 *     * Otherwise trigger the standard BDApps OTP flow and
 *       return `{token: null, requires_otp: true, reference_no}`.
 *   - verify(): confirm OTP with BDApps, flip user+row to
 *     `pending` (or directly to `registered` on a synchronous
 *     `REGISTERED` reply), and issue a Sanctum token. The UI
 *     reads the resulting `subscription_status` via `/auth/me`
 *     to switch between chat and the "Payment pending" page.
 *   - me(): return phone, `subscription_status`, and
 *     `is_verified` (first-OTP event).
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

            // Token-bearing users (`pending` or `registered`)
            // already hold a Sanctum-token-issuing state; skip
            // the OTP step entirely. We still acknowledge the
            // login via /sms/send so they see a record on
            // their phone.
            if ($user->isTokenBearing()) {
                $token = $user->createToken('mobile')->plainTextToken;

                $this->subscriptionService->notifyLogin($user);

                return $this->sendSuccessResponse([
                    'token' => $token,
                    'requires_otp' => false,
                    'reference_no' => null,
                    'subscription_status' => $user->subscription_status,
                    'is_verified' => $user->isVerified(),
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

            // Token-issuance point: the user is now either
            // `pending` (BDApps mid-charge) or `registered`
            // (synchronous REGISTERED reply). The Sanctum token
            // is issued in either case; the UI uses
            // `subscription_status` from /auth/me to decide which
            // page to render.
            $token = $user->createToken('mobile')->plainTextToken;

            return $this->sendSuccessResponse([
                'token' => $token,
                'subscription_status' => $user->subscription_status,
                'is_verified' => $user->isVerified(),
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
                'is_verified' => $user->isVerified(),
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
}
