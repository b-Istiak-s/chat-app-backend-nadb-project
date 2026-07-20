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

    public function latestForUser(int $userId): ?BdappsSubscription
    {
        return BdappsSubscription::where('user_id', $userId)
            ->orderByDesc('id')
            ->first();
    }

    public function activeForUser(int $userId): ?BdappsSubscription
    {
        return BdappsSubscription::where('user_id', $userId)
            ->where('status', BdappsSubscription::STATUS_REGISTERED)
            ->orderByDesc('id')
            ->first();
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
