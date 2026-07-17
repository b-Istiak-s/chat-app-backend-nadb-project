<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BdappsSubscription extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_UNREGISTERED = 'unregistered';

    public const STATUS_CHARGE_FAILED = 'charge_failed';

    protected $fillable = [
        'user_id',
        'phone',
        'subscriber_id',
        'gateway_subscriber_id',
        'status',
        'bdapps_subscription_status',
        'reference_no',
        'application_id',
        'started_at',
        'cancelled_at',
        'last_notified_at',
        'raw_register_response',
        'raw_otp_request_response',
        'raw_status_response',
        'error_code',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_notified_at' => 'datetime',
            'raw_register_response' => 'array',
            'raw_otp_request_response' => 'array',
            'raw_status_response' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REGISTERED);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_REGISTERED;
    }
}
