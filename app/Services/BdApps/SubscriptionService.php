<?php

namespace App\Services\BdApps;

use App\Exceptions\BdApps\BdAppsException;
use App\Models\BdappsSubscription;
use App\Models\User;
use App\Repositories\BdappsSubscriptionRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the BDApps subscription lifecycle around a User.
 * All raw HTTP work lives in BdAppsService; data access goes through
 * BdappsSubscriptionRepository.
 *
 * Every error path writes a structured entry to the dedicated `bdapps`
 * log channel so the full conversation between our backend and the
 * Robi gateway lands in storage/logs/bdapps-YYYY-MM-DD.log.
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

        try {
            $otpResult = $this->bdApps->requestOtp($user->phone);
        } catch (BdAppsException $e) {
            // Gateway rejected the request (e.g. E1312 invalid payload,
            // E1325 invalid subscriber id). Log structured fields so we
            // can correlate to the matching bdapps.otp.request entry.
            Log::channel('bdapps')->error('bdapps.subscribe_failed_on_register', [
                'phone' => $user->phone,
                'status_code' => $e->statusCode,
                'status_detail' => $e->statusDetail,
            ]);
            throw $e;
        } catch (ConnectionException $e) {
            // Transport-level failure (timeout, DNS, TLS, refused).
            // No reference_no was issued; we don't even create a row —
            // the next login will retry from scratch.
            Log::channel('bdapps')->error('bdapps.subscribe_failed_on_register', [
                'phone' => $user->phone,
                'transport_error' => $e->getMessage(),
            ]);
            throw $e;
        }

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
        } catch (BdAppsException $e) {
            Log::channel('bdapps')->error('bdapps.unsubscribe_failed', [
                'user_id' => $user->id,
                'phone' => $user->phone,
                'status_code' => $e->statusCode,
                'status_detail' => $e->statusDetail,
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
        } catch (ConnectionException $e) {
            Log::channel('bdapps')->error('bdapps.unsubscribe_failed', [
                'user_id' => $user->id,
                'phone' => $user->phone,
                'transport_error' => $e->getMessage(),
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

        try {
            $result = $this->bdApps->requestOtp($user->phone);
        } catch (BdAppsException $e) {
            Log::channel('bdapps')->error('bdapps.otp_request_failed', [
                'phone' => $user->phone,
                'status_code' => $e->statusCode,
                'status_detail' => $e->statusDetail,
            ]);
            throw $e;
        } catch (ConnectionException $e) {
            Log::channel('bdapps')->error('bdapps.otp_request_failed', [
                'phone' => $user->phone,
                'transport_error' => $e->getMessage(),
            ]);
            throw $e;
        }

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
     * success — we mirror that locally. Wrong/invalid OTPs come back
     * as a gateway error and are logged to the bdapps channel.
     */
    public function verifyOtp(User $user, string $otp): array
    {
        $referenceNo = $this->ensureOtpReference($user);

        try {
            $result = $this->bdApps->verifyOtp($referenceNo, $otp);
        } catch (BdAppsException $e) {
            Log::channel('bdapps')->error('bdapps.verify_failed', [
                'user_id' => $user->id,
                'phone' => $user->phone,
                'reference_no' => $referenceNo,
                'status_code' => $e->statusCode,
                'status_detail' => $e->statusDetail,
            ]);
            throw $e;
        } catch (ConnectionException $e) {
            Log::channel('bdapps')->error('bdapps.verify_failed', [
                'user_id' => $user->id,
                'phone' => $user->phone,
                'reference_no' => $referenceNo,
                'transport_error' => $e->getMessage(),
            ]);
            throw $e;
        }

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
