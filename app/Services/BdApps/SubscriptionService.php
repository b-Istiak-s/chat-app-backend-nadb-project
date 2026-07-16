<?php

namespace App\Services\BdApps;

use App\Models\BdappsSubscription;
use App\Models\User;
use App\Repositories\BdappsSubscriptionRepository;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the BDApps subscription lifecycle around a User.
 * All raw HTTP work lives in BdAppsService; data access goes through
 * BdappsSubscriptionRepository.
 */
class SubscriptionService
{
    public function __construct(
        private BdAppsService $bdApps,
        private BdappsSubscriptionRepository $subscriptions,
    ) {}

    /**
     * Start (or restart) a subscription for the given user. Calls
     * /otp/request and persists the returned referenceNo so the
     * matching /otp/verify call can succeed.
     *
     * Returns:
     *   [
     *     'subscription' => BdappsSubscription,
     *     'reference_no' => ?string,
     *   ]
     */
    public function startSubscription(User $user): array
    {
        // Already active? Skip so we don't double-charge at the gateway.
        $existing = $this->subscriptions->activeForUser($user->id);
        if ($existing) {
            return [
                'subscription' => $existing,
                'reference_no' => null,
            ];
        }

        $otpResult = $this->bdApps->requestOtp($user->phone);

        $subscription = $this->subscriptions->create([
            'user_id' => $user->id,
            'phone' => $user->phone,
            'subscriber_id' => $this->bdApps->formatSubscriberId($user->phone),
            'status' => BdappsSubscription::STATUS_PENDING,
            'bdapps_subscription_status' => null,
            'reference_no' => $otpResult['reference_no'] ?? null,
            'application_id' => config('bdapps.application_id'),
            'started_at' => now(),
            'raw_otp_request_response' => $otpResult['raw'] ?? null,
        ]);

        if (! ($otpResult['ok'] ?? false)) {
            Log::warning('bdapps.otp_request_failed', [
                'user_id' => $user->id,
                'status_code' => $otpResult['status_code'] ?? null,
                'status_detail' => $otpResult['status_detail'] ?? null,
            ]);
        }

        return [
            'subscription' => $subscription,
            'reference_no' => $otpResult['reference_no'] ?? null,
        ];
    }

    /**
     * Cancel the user's subscription. Best-effort: a gateway failure
     * doesn't prevent us from marking them inactive locally because
     * the next login will reconcile via getStatus anyway.
     */
    public function cancelSubscription(User $user): BdappsSubscription
    {
        $subscription = $this->subscriptions->latestForUser($user->id);

        try {
            $result = $this->bdApps->unsubscribe($user->phone);

            if ($subscription) {
                $this->subscriptions->update($subscription->id, [
                    'status' => BdappsSubscription::STATUS_UNREGISTERED,
                    'bdapps_subscription_status' => $result['subscription_status'] ?? 'UNREGISTERED',
                    'cancelled_at' => now(),
                ]);
                $subscription->refresh();
            } else {
                $subscription = $this->subscriptions->create([
                    'user_id' => $user->id,
                    'phone' => $user->phone,
                    'subscriber_id' => $this->bdApps->formatSubscriberId($user->phone),
                    'status' => BdappsSubscription::STATUS_UNREGISTERED,
                    'bdapps_subscription_status' => $result['subscription_status'] ?? 'UNREGISTERED',
                    'cancelled_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('bdapps.unsubscribe_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            if ($subscription) {
                $this->subscriptions->update($subscription->id, [
                    'status' => BdappsSubscription::STATUS_UNREGISTERED,
                    'cancelled_at' => now(),
                    'error_code' => 'unsubscribe_failed',
                    'error_message' => $e->getMessage(),
                ]);
                $subscription->refresh();
            }
        }

        $user->forceFill([
            'subscription_status' => 'unsubscribed',
            'subscribed_at' => null,
        ])->save();

        return $subscription;
    }

    /**
     * Make sure the user has a referenceNo for the next verify call.
     * Returns the reference_no (newly fetched or reused).
     */
    public function ensureOtpReference(User $user): string
    {
        $subscription = $this->subscriptions->latestForUser($user->id);

        if ($subscription && $subscription->reference_no) {
            return $subscription->reference_no;
        }

        $result = $this->bdApps->requestOtp($user->phone);
        $referenceNo = $result['reference_no'] ?? null;

        if ($subscription && $referenceNo) {
            $this->subscriptions->update($subscription->id, [
                'reference_no' => $referenceNo,
                'raw_otp_request_response' => $result['raw'] ?? null,
            ]);
        }

        return (string) $referenceNo;
    }

    /**
     * Verify the OTP at BDApps and, on success, mark the user as
     * subscribed. The gateway flips subscriptionStatus to REGISTERED on
     * success — we mirror that locally.
     */
    public function verifyOtp(User $user, string $otp): array
    {
        $referenceNo = $this->ensureOtpReference($user);

        $result = $this->bdApps->verifyOtp($referenceNo, $otp);

        if ($result['ok'] ?? false) {
            $user->forceFill([
                'subscription_status' => 'subscribed',
                'subscribed_at' => now(),
                'phone_verified_at' => now(),
            ])->save();

            $subscription = $this->subscriptions->latestForUser($user->id);
            if ($subscription) {
                $this->subscriptions->update($subscription->id, [
                    'status' => BdappsSubscription::STATUS_REGISTERED,
                    'bdapps_subscription_status' => $result['subscription_status']
                        ?? BdappsSubscription::STATUS_REGISTERED,
                    'reference_no' => null,
                ]);
            }
        }

        return $result;
    }

    /**
     * Handle an incoming notify webhook. The status field is the
     * source of truth — we mirror it onto both the user and the
     * latest subscription row. Idempotent.
     */
    public function applyNotifyStatus(User $user, string $status, ?string $frequency = null): BdappsSubscription
    {
        $isRegistered = strtoupper($status) === 'REGISTERED';

        $subscription = $this->subscriptions->latestForUser($user->id);

        if ($subscription) {
            $this->subscriptions->update($subscription->id, [
                'status' => $isRegistered
                    ? BdappsSubscription::STATUS_REGISTERED
                    : BdappsSubscription::STATUS_UNREGISTERED,
                'bdapps_subscription_status' => strtoupper($status),
                'cancelled_at' => $isRegistered ? null : ($subscription->cancelled_at ?? now()),
            ]);
            $subscription->refresh();
        } else {
            $subscription = $this->subscriptions->create([
                'user_id' => $user->id,
                'phone' => $user->phone,
                'subscriber_id' => $this->bdApps->formatSubscriberId($user->phone),
                'status' => $isRegistered
                    ? BdappsSubscription::STATUS_REGISTERED
                    : BdappsSubscription::STATUS_UNREGISTERED,
                'bdapps_subscription_status' => strtoupper($status),
                'cancelled_at' => $isRegistered ? null : now(),
            ]);
        }

        $user->forceFill([
            'subscription_status' => $isRegistered ? 'subscribed' : 'unsubscribed',
            'subscribed_at' => $isRegistered
                ? ($user->subscribed_at ?? now())
                : null,
        ])->save();

        return $subscription;
    }
}
