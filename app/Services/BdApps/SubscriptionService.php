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
     * Verify the OTP at BDApps and reflect the gateway's verdict
     * locally. Strict activation: the user only flips to `subscribed`
     * when the gateway confirms `REGISTERED`. If the gateway returns a
     * "still charging" status (e.g. `INITIAL CHARGING PENDING`), the
     * row stays at `status='pending'` and the user stays at
     * `unsubscribed` — they cannot log in until the 10-second
     * `PollSubscriptionStatusJob` (or the cron safety net) confirms
     * REGISTERED.
     *
     * Wrong/invalid OTPs come back as a gateway error and are logged
     * to the bdapps channel.
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

        // Mirror the gateway's verdict on the user record. The
        // previous optimistic-flip behaviour (marking the user
        // subscribed even when the gateway said pending) is gone —
        // see agents/lessons.md for the rationale.
        if ($isRegistered) {
            $user->forceFill([
                'subscription_status' => 'subscribed',
                'subscribed_at' => now(),
                'phone_verified_at' => now(),
            ])->save();
        } else {
            $user->forceFill([
                'phone_verified_at' => now(),
            ])->save();
        }

        $subscription = $this->subscriptions->latestForUser($user->id);
        if ($subscription) {
            $this->subscriptions->update($subscription->id, [
                // REGISTERED → fully active. A known pending status
                // keeps the row at `pending` so the post-verify job
                // and the cron can reconcile later. Anything else
                // (UNREGISTERED, unexpected values, transport-style
                // success without a status field) defaults to
                // `registered` — the gateway accepted the verify, and
                // the row staying pending forever would only happen if
                // we invented a brand-new failure mode that the
                // gateway hasn't told us about.
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
     * Finalize a user's subscription state by polling the gateway
     * once and applying the result via `applyNotifyStatus()`.
     *
     * Called by the 10-second delayed `PollSubscriptionStatusJob`
     * after a successful OTP verify, and by the dashboard's
     * "Refresh status now" action. Returns the resolved subscription
     * row.
     *
     * If the row is no longer pending (e.g. the cron got there first)
     * the call is a no-op for the gateway but still records the
     * latest status on the row.
     */
    public function finalizeActivation(User $user): ?BdappsSubscription
    {
        $subscription = $this->subscriptions->latestForUser($user->id);
        if (! $subscription) {
            return null;
        }

        $gatewaySubscriberId = $subscription->gateway_subscriber_id
            ?: $subscription->subscriber_id;

        try {
            $result = $this->bdApps->getStatus($user->phone, $gatewaySubscriberId);
        } catch (BdAppsException|ConnectionException $e) {
            Log::channel('bdapps')->warning('bdapps.finalize_activation_failed', [
                'user_id' => $user->id,
                'phone' => $user->phone,
                'status_code' => $e instanceof BdAppsException ? $e->statusCode : null,
                'error' => $e->getMessage(),
            ]);

            return $subscription;
        }

        $status = strtoupper((string) ($result['subscription_status'] ?? ''));
        if ($status === '') {
            return $subscription;
        }

        if (! empty($result['gateway_subscriber_id'])) {
            $this->subscriptions->update($subscription->id, [
                'gateway_subscriber_id' => $result['gateway_subscriber_id'],
            ]);
        }

        $this->applyNotifyStatus($user, $status);
        $subscription->refresh();

        return $subscription;
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
     * If `bdapps.login_sms_notify_enabled` is false (the default),
     * this is a no-op — useful for tests / local dev where we don't
     * want to spam the gateway. Read via config() (cached) rather than
     * env() — outside of config loading, env() returns null in
     * production and the gate silently evaluates false.
     */
    public function notifyLogin(User $user): void
    {
        if (! (bool) config('bdapps.login_sms_notify_enabled', false)) {
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
