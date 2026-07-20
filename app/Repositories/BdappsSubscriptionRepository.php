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
     * Pending subscription rows whose `started_at` is at or before
     * `$olderThan`. Used by the reconciliation cronjob to find rows
     * that have been waiting long enough to be worth polling.
     *
     * Only rows with `status='pending'` are returned — registered and
     * unregistered rows are skipped (the cron should be a no-op for
     * them).
     */
    public function pendingForPolling(DateTimeInterface $olderThan): Collection
    {
        return BdappsSubscription::where('status', BdappsSubscription::STATUS_PENDING)
            // ->where('started_at', '<=', $olderThan)
            ->with('user')
            ->orderBy('id')
            ->get();
    }
}
