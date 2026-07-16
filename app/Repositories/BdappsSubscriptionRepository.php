<?php

namespace App\Repositories;

use App\Models\BdappsSubscription;

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
}
