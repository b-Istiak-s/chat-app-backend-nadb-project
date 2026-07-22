<?php

namespace App\Repositories;

use App\Models\BdappsSubscription;
use DateTimeInterface;
use Illuminate\Support\Collection;

class BdappsSubscriptionRepository
{
    public function create(array $attributes): BdappsSubscription
    {
        return BdappsSubscription::create($attributes);
    }

    public function update(int $id, array $attributes): bool
    {
        return BdappsSubscription::where('id', $id)->update($attributes) > 0;
    }

    public function findBySubscriberId(string $subscriberId): ?BdappsSubscription
    {
        return BdappsSubscription::where('subscriber_id', $subscriberId)->first();
    }

    /**
     * Look up a subscription by the gateway-canonical base64
     * `subscriberId` (persisted on the row as
     * `gateway_subscriber_id`). The gateway returns this masked value
     * in two places: the /otp/verify response, and incoming notify
     * webhooks. Matching it directly is the only way to correlate a
     * masked webhook payload back to the subscription row — we have
     * no way to "unmask" the base64 string back into a phone number
     * on our side, and attempting to do so produces the kind of
     * nonsense logged by `bdapps.notify_unknown_phone`.
     */
    public function findByGatewaySubscriberId(string $gatewaySubscriberId): ?BdappsSubscription
    {
        return BdappsSubscription::where('gateway_subscriber_id', $gatewaySubscriberId)->first();
    }

    public function latestForUser(int $userId): ?BdappsSubscription
    {
        return BdappsSubscription::where('user_id', $userId)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The user's most recent live subscription row — covers both
     * `pending` (OTP verified, BDApps mid-charge) and `registered`
     * (fully active). Used by `SubscriptionService::startSubscription()`
     * to short-circuit re-OTP for already-subscribed users.
     *
     * For strict mid-charge filtering (e.g. the cron poll, which
     * must not re-ping already-`registered` rows), use a
     * `BdappsSubscription::pending()` query directly instead.
     */
    public function liveForUser(int $userId): ?BdappsSubscription
    {
        return BdappsSubscription::where('user_id', $userId)
            ->live()
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Backwards-compatible alias for `liveForUser()` from the
     * `registered` model. Kept during the migration window.
     *
     * @deprecated Use liveForUser() instead.
     */
    public function activeForUser(int $userId): ?BdappsSubscription
    {
        return $this->liveForUser($userId);
    }

    /**
     * Pending subscription rows older than `$olderThan`. Used by the
     * reconciliation cronjob to find rows that are still awaiting a
     * gateway verdict.
     *
     * Only rows with `status='pending'` are returned — registered and
     * unregistered rows are skipped (the cron should be a no-op for
     * them).
     *
     * Why an age filter at all? The per-user
     * `PollSubscriptionStatusJob` is the primary reconciliation path
     * and runs ~10 seconds after the OTP verifies. The cron's job is
     * the safety net — pick up anything the worker missed (worker
     * down at the moment the job was scheduled). A 1-minute minimum
     * gives the per-user job comfortable headroom to win the race;
     * it deliberately doesn't poll brand-new rows that the worker
     * is about to handle.
     *
     * Important: this is `started_at <= $olderThan`, not `==`. A
     * row created at 11:59:59 will satisfy this predicate at every
     * cron tick after 12:00:59 — it will be re-polled indefinitely
     * until the gateway flips it to a terminal state (REGISTERED or
     * UNREGISTERED). The condition is monotonic over time, not a
     * once-only window.
     */
    public function pendingForPolling(DateTimeInterface $olderThan): Collection
    {
        return BdappsSubscription::where('status', BdappsSubscription::STATUS_PENDING)
            ->where('started_at', '<=', $olderThan)
            ->with('user')
            ->orderBy('id')
            ->get();
    }
}
