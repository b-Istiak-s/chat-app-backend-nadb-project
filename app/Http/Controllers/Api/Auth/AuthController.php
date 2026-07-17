<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\StartRequest;
use App\Http\Requests\Api\Auth\VerifyOtpRequest;
use App\Models\BdappsSubscription;
use App\Models\User;
use App\Services\BdApps\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single-user-type auth flow:
 *   - start(): find-or-create user by phone.
 *     * If the user is `subscribed` OR has a `pending` subscription
 *       row, issue a Sanctum token directly (no OTP, no password)
 *       and fire a courtesy SMS via BDApps /sms/send so the user
 *       sees a record of the login.
 *     * Otherwise trigger the standard BDApps OTP flow and return
 *       {token: null, requires_otp: true, reference_no: ...}.
 *   - verify(): confirm OTP with BDApps and mark user subscribed.
 *   - me(): return current user phone + subscription status.
 *   - logout(): revoke current Sanctum token.
 *   - unsubscribe(): cancel BDApps subscription.
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
                ['subscription_status' => 'unsubscribed'],
            );

            // Logged-in users (subscribed, OR have a pending row that
            // the gateway is still finalising) skip OTP entirely. We
            // still acknowledge the login via /sms/send so they see
            // a record on their phone.
            if ($this->userCanSkipOtp($user)) {
                $token = $user->createToken('mobile')->plainTextToken;

                $this->subscriptionService->notifyLogin($user);

                return $this->sendSuccessResponse([
                    'token' => $token,
                    'requires_otp' => false,
                    'reference_no' => null,
                    'subscription_status' => $user->subscription_status,
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

            $token = $user->createToken('mobile')->plainTextToken;

            return $this->sendSuccessResponse([
                'token' => $token,
                'subscription_status' => $result['subscription_status'] ?? 'REGISTERED',
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
     * True when the user can skip the OTP step entirely — i.e. they
     * have an active or in-flight subscription. "subscribed" is the
     * steady-state active case; "pending" is a user we optimistically
     * granted a token to (during an INITIAL CHARGING PENDING window)
     * whose subscription row is still being reconciled. Either way we
     * trust them.
     */
    private function userCanSkipOtp(User $user): bool
    {
        if ($user->isSubscribed()) {
            return true;
        }

        return $user->bdappsSubscriptions()
            ->where('status', BdappsSubscription::STATUS_PENDING)
            ->exists();
    }
}
