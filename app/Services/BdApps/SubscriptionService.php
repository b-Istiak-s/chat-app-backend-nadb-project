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
 * State machine (four values, used for both row.status and
 * users.subscription_status):
 *
 *   unverified → pending → registered
 *                     ↘
 *                     unregistered (was: cancelled)
 *
 *   - unverified: OTP not yet entered (or the previous session was
 *     unregistered and the user needs to start over). No token issued.
 *   - pending: OTP verified by us; the user is signed in and
 *     receives a Sanctum token. The gateway is still charging —
 *     `bdapps_subscriptions.bdapps_subscription_status` carries
 *     the literal BDApps reply for display. **Feature access is
 *     locked** — the UI shows "Payment pending" until the row
 *     flips to `registered`.
 *   - registered: BDApps confirmed `REGISTERED`. The user has
 *     full feature access (chat, APK download). This is the only
 *     `isFullySubscribed()` state.
 *   - unregistered: terminal. User cancelled, or BDApps returned
 *     a terminal non-`REGISTERED` status
 *     (`UNREGISTERED`/`EXPIRED`). No token; next login starts a
 *     fresh OTP.
 *
 * Transition `pending → registered` happens exactly once via
 * `applyNotifyStatus()` (webhook, per-user poll, or safety-net
 * cron) or — for a synchronous `REGISTERED` reply — directly in
 * `verifyOtp()`.
 *
 * `User::isVerified()` is **decoupled** from this state machine;
 * it reads `phone_verified_at IS NOT NULL` and represents the
 * first-OTP "this phone belongs to this user" event, not
 * subscription status.
 *
 * Every error path writes a structured entry to the dedicated
 * `bdapps` log channel so the full conversation between our backend
 * and the Robi gateway lands in
 * storage/logs/bdapps-YYYY-MM-DD.log.
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
        // Already subscribed (pending or registered)? Skip so we
        // don't double-charge at the gateway. The user is signed in
        // (token issued previously); we just hand the existing row
        // back.
        $existing = $this->subscriptions->liveForUser($user->id);
        if ($existing) {
            return [
                'subscription' => $existing,
                'reference_no' => null,
            ];
        }

        try {
            $otpResult = $this->bdApps->requestOtp($user->phone);
        } catch (BdAppsException $e) {
            Log::channel('bdapps')->error('bdapps.subscribe_failed_on_register', [
                'phone' => $user->phone,
                'status_code' => $e->statusCode,
                'status_detail' => $e->statusDetail,
            ]);
            throw $e;
        } catch (ConnectionException $e) {
            Log::channel('bdapps')->error('bdapps.subscribe_failed_on_register', [
                'phone' => $user->phone,
                'transport_error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Fresh row at `unverified` — the user must now enter the
        // OTP. We do NOT optimistically flip the user to `pending`;
        // that's `verifyOtp()`'s job.
        $subscription = $this->subscriptions->create([
            'user_id' => $user->id,
            'phone' => $user->phone,
            'subscriber_id' => $this->bdApps->formatSubscriberId($user->phone),
            'status' => BdappsSubscription::STATUS_UNVERIFIED,
            'bdapps_subscription_status' => null,
            'reference_no' => $otpResult['reference_no'] ?? null,
            'application_id' => config('bdapps.application_id'),
            'started_at' => now(),
            'raw_otp_request_response' => $otpResult['raw'] ?? null,
        ]);

        if (! $user->isVerified()) {
            $user->forceFill([
                'subscription_status' => 'unverified',
            ])->save();
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
     *
     * On success, the user lands at `unregistered` and the row at
     * `unregistered`. The user must complete a fresh OTP to subscribe
     * again.
     */
    public function cancelSubscription(User $user): BdappsSubscription
    {
        $subscription = $this->subscriptions->latestForUser($user->id);

        $gatewaySubscriberId = $subscription?->gateway_subscriber_id;

        try {
            $result = $this->bdApps->unsubscribe($user->phone, $gatewaySubscriberId);

            if ($subscription) {
                $this->subscriptions->update($subscription->id, [
                    'status' => BdappsSubscription::STATUS_UNREGISTERED,
                    'bdapps_subscription_status' => $result['subscription_status'] ?? 'UNREGISTERED',
                    'cancelled_at' => now(),
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
            'subscription_status' => 'unregistered',
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
     * locally. Two outcomes:
     *
     *   - Gateway returns `REGISTERED` synchronously: row + user
     *     move directly to `registered`. Token-bearing AND full
     *     feature access — the user skips the "Payment pending"
     *     page entirely. This is the fast-path for gateways that
     *     confirm payment within the verify call.
     *   - Gateway returns PENDING-family: row + user move to
     *     `pending`. The user is token-bearing but feature access
     *     is locked until `applyNotifyStatus()` sees REGISTERED.
     *
     * Token issuance happens at the controller layer right after
     * this method returns; the user is in `subscription_status`
     * `pending` or `registered` by then.
     *
     * On terminal-failure (`UNREGISTERED`/`EXPIRED`), the user
     * and row land at `unregistered` — the next `/auth/start`
     * triggers a fresh OTP.
     *
     * The gateway's base64 `subscriberId` from the response is
     * persisted as `gateway_subscriber_id` on the row so future
     * /subscription/send calls can use the gateway-canonical
     * form.
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
        $isTerminalFailure = $this->bdApps->isTerminalFailure($gatewayStatus);
        $isRegistered = $gatewayStatus === 'REGISTERED';

        // Token issuance: the user is phone-verified regardless of
        // outcome. If the gateway accepted the OTP (non-terminal),
        // flip the subscription state — straight to `registered`
        // on a synchronous REGISTERED, otherwise to `pending`.
        if ($gatewayStatus !== '' && ! $isTerminalFailure) {
            $newState = $isRegistered ? 'registered' : 'pending';

            $user->forceFill([
                'subscription_status' => $newState,
                'subscribed_at' => $user->subscribed_at ?? now(),
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
                'status' => $isRegistered
                    ? BdappsSubscription::STATUS_REGISTERED
                    : BdappsSubscription::STATUS_PENDING,
                'bdapps_subscription_status' => $gatewayStatus !== ''
                    ? $gatewayStatus
                    : null,
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
     * "Refresh status now" action. Returns the resolved
     * subscription row.
     *
     * Only meaningful for verified users (i.e. `pending` row). For
     * `unverified` or `unregistered` rows the call returns early
     * without hitting the gateway — the queue/cron scoping rule
     * that "queue and poll run only for pending rows" is enforced
     * here at the service layer as well.
     */
    public function finalizeActivation(User $user): ?BdappsSubscription
    {
        $subscription = $this->subscriptions->latestForUser($user->id);
        if (! $subscription) {
            return null;
        }

        // Skip the gateway entirely for non-pending rows. The
        // 10s per-user job and the cron should not be doing work
        // for rows that aren't actively awaiting a BDApps verdict.
        if ($subscription->status !== BdappsSubscription::STATUS_PENDING) {
            return $subscription;
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
     * (subscription_status='pending' — token-bearing) skips the OTP
     * step. The SMS is a pure audit/UX courtesy — the login is
     * already complete by the time we get here, so we deliberately
     * swallow transport / gateway failures: a missed SMS should
     * never log the user out or fail the auth response.
     *
     * If `bdapps.login_sms_notify_enabled` is false (the default),
     * this is a no-op — useful for tests / local dev where we don't
     * want to spam the gateway. Read via config() (cached) rather
     * than env() — outside of config loading, env() returns null in
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

        // Prefer the gateway's own base64 subscriberId (from the
        // user's latest subscription row). The gateway treats that
        // as canonical for /sms/send; using a locally-derived
        // `tel:880…` form is a documented mismatch. Fall back to
        // phone if no row exists.
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
     * Apply a gateway-reported subscription status to both the
     * latest subscription row and the user. Used by:
     *   - the notify webhook (REGISTERED / UNREGISTERED / EXPIRED)
     *   - the dashboard "Refresh status now" button via
     *     `finalizeActivation()`
     *   - the 10s per-user PollSubscriptionStatusJob
     *   - the every-minute safety-net cron
     *
     * Behaviour:
     *
     *   - REGISTERED: row → `registered`, user → `registered`.
     *     Money taken, full feature access unlocked.
     *   - PENDING-family (`INITIAL CHARGING PENDING`,
     *     `CHARGE_PENDING`, `PENDING`): row + user stay at
     *     `pending`. Mirror column records the literal reply.
     *     Feature access still locked — the UI shows "Payment
     *     pending".
     *   - Terminal non-`REGISTERED` (`UNREGISTERED`, `EXPIRED`):
     *     row → `unregistered`; user → `unregistered`. Sets
     *     `cancelled_at` if not already set. Next login triggers
     *     a fresh OTP.
     */
    public function applyNotifyStatus(User $user, string $status, ?string $frequency = null): BdappsSubscription
    {
        $normalized = strtoupper($status);
        $isRegistered = $normalized === 'REGISTERED';
        $isPending = $this->bdApps->isPendingStatus($normalized);
        $isTerminalCancellation = ! $isRegistered && ! $isPending && $normalized !== '';

        $subscription = $this->subscriptions->latestForUser($user->id);

        // Row update: REGISTERED → 'registered'; terminal →
        // 'unregistered'; otherwise stay at 'pending'. The mirror
        // column records the literal reply for forensics / display.
        $rowStatus = $isRegistered
            ? BdappsSubscription::STATUS_REGISTERED
            : ($isTerminalCancellation
                ? BdappsSubscription::STATUS_UNREGISTERED
                : BdappsSubscription::STATUS_PENDING);

        if ($subscription) {
            $this->subscriptions->update($subscription->id, [
                'status' => $rowStatus,
                'bdapps_subscription_status' => $normalized,
                'last_notified_at' => now(),
                'cancelled_at' => $isTerminalCancellation
                    ? ($subscription->cancelled_at ?? now())
                    : null,
            ]);
            $subscription->refresh();
        } else {
            $subscription = $this->subscriptions->create([
                'user_id' => $user->id,
                'phone' => $user->phone,
                'subscriber_id' => $this->bdApps->formatSubscriberId($user->phone),
                'status' => $rowStatus,
                'bdapps_subscription_status' => $normalized,
                'last_notified_at' => now(),
                'cancelled_at' => $isTerminalCancellation ? now() : null,
            ]);
        }

        // User update — flips to `registered` on a REGISTERED reply
        // and to `unregistered` on a terminal reply. PENDING-family
        // replies are no-ops for the user (they were already at
        // `pending`).
        if ($isRegistered) {
            $user->forceFill([
                'subscription_status' => 'registered',
            ])->save();
        } elseif ($isTerminalCancellation) {
            $user->forceFill([
                'subscription_status' => 'unregistered',
                'subscribed_at' => null,
            ])->save();
        }

        return $subscription;
    }
}
