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

        // Prefer the gateway-canonical base64 subscriber id when we
        // have it (more likely to round-trip cleanly on the gateway).
        $gatewaySubscriberId = $subscription?->gateway_subscriber_id;

        try {
            $result = $this->bdApps->unsubscribe($user->phone, $gatewaySubscriberId);

            if ($subscription) {
                $this->subscriptions->update($subscription->id, [
                    'status' => BdappsSubscription::STATUS_UNREGISTERED,
                    'bdapps_subscription_status' => $result['subscription_status'] ?? 'UNREGISTERED',
                    'cancelled_at' => now(),
                    // Refresh the base64 id if the gateway echoed it back.
                    'gateway_subscriber_id' => $result['gateway_subscriber_id']
                        ?? $subscription->gateway_subscriber_id,
                ]);
                $subscription->refresh();
            } else {
                $subscription = $this->subscriptions->create([
                    'user_id' => $user->id,
                    'phone' => $user->phone,
                    'subscriber_id' => $this->bdApps->formatSubscriberId($user->phone),
                    'gateway_subscriber_id' => $gatewaySubscriberId,
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
     *
     * If the gateway returns one of the "still charging" statuses
     * (e.g. `INITIAL CHARGING PENDING`) the user is optimistically
     * marked subscribed (so the auth flow can return a Sanctum token)
     * but the subscription row stays at `status='pending'` so the
     * `bdapps:poll-pending` cron can reconcile later.
     *
     * The gateway's base64 `subscriberId` from the response is
     * persisted as `gateway_subscriber_id` on the row, so future
     * /subscription/send calls can use the gateway-canonical form.
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

        $gatewayStatus = strtoupper((string) ($result['subscription_status'] ?? ''));
        $isRegistered = $gatewayStatus === 'REGISTERED';
        $isPending = $this->bdApps->isPendingStatus($gatewayStatus);

        // Optimistic activation: user gets a token regardless. The
        // local subscription row carries the more truthful state.
        $user->forceFill([
            'subscription_status' => 'subscribed',
            'subscribed_at' => now(),
            'phone_verified_at' => now(),
        ])->save();

        $subscription = $this->subscriptions->latestForUser($user->id);
        if ($subscription) {
            $this->subscriptions->update($subscription->id, [
                // REGISTERED → fully active. Anything else → if it's a
                // known "still charging" status, keep pending so the
                // cron can flip later; otherwise default to registered
                // (defensive — gateway already accepted the verify).
                'status' => $isRegistered
                    ? BdappsSubscription::STATUS_REGISTERED
                    : ($isPending
                        ? BdappsSubscription::STATUS_PENDING
                        : BdappsSubscription::STATUS_REGISTERED),
                'bdapps_subscription_status' => $gatewayStatus !== ''
                    ? $gatewayStatus
                    : BdappsSubscription::STATUS_REGISTERED,
                'reference_no' => null,
                'gateway_subscriber_id' => $result['gateway_subscriber_id']
                    ?? $subscription->gateway_subscriber_id,
            ]);
        }

        return $result;
    }

    /**
     * Send a courtesy login-notification SMS via BDApps /sms/send.
     *
     * Called from the auth flow when an already-trusted user
     * (subscribed or has a pending row) skips the OTP step. The SMS
     * is a pure audit/UX courtesy — the login is already complete by
     * the time we get here, so we deliberately swallow transport /
     * gateway failures: a missed SMS should never log the user out
     * or fail the auth response.
     *
     * If BDAPPS_LOGIN_SMS_NOTIFY_ENABLED is false (or unset to "0"),
     * this is a no-op — useful for tests / local dev where we don't
     * want to spam the gateway.
     */
    public function notifyLogin(User $user): void
    {
        if (! filter_var(env('BDAPPS_LOGIN_SMS_NOTIFY_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $message = sprintf(
            'ChatApp: You just signed in to your ChatApp account on %s. '
            .'If this was not you, please contact support.',
            now()->format('Y-m-d H:i'),
        );

        // Prefer the gateway's own base64 subscriberId (from the user's
        // latest subscription row). The gateway treats that as canonical
        // for /sms/send; using a locally-derived `tel:880…` form is a
        // documented mismatch. Fall back to phone if no row exists.
        $gatewaySubscriberId = $user->bdappsSubscriptions()
            ->orderByDesc('id')
            ->value('gateway_subscriber_id');

        try {
            $this->bdApps->sendSms(
                $user->phone,
                $message,
                gatewaySubscriberId: $gatewaySubscriberId,
            );
        } catch (BdAppsException $e) {
            Log::channel('bdapps')->warning('bdapps.login_notify_failed', [
                'user_id' => $user->id,
                'phone' => $user->phone,
                'status_code' => $e->statusCode,
                'status_detail' => $e->statusDetail,
            ]);
        } catch (ConnectionException $e) {
            Log::channel('bdapps')->warning('bdapps.login_notify_failed', [
                'user_id' => $user->id,
                'phone' => $user->phone,
                'transport_error' => $e->getMessage(),
            ]);
        }
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
