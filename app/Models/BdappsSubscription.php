<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BdappsSubscription extends Model
{
    /**
     * OTP not yet entered. Created fresh on `startSubscription()`,
     * abandoned-mid-flow rows get re-mapped here too (e.g. user
     * closed the app after submitting their phone but before
     * entering the OTP).
     */
    public const STATUS_UNVERIFIED = 'unverified';

    /**
     * OTP verified by us. The user has a token and is signed in.
     * The gateway may still be charging (mirror column carries
     * the literal `INITIAL CHARGING PENDING` / `REGISTERED` /
     * whatever). The "live" set.
     */
    public const STATUS_PENDING = 'pending';

    /**
     * Terminal: user cancelled OR the gateway returned a terminal
     * non-`REGISTERED` status (`UNREGISTERED` / `EXPIRED`). No
     * token-bearing. Next login starts a fresh OTP.
     */
    public const STATUS_CANCELLED = 'cancelled';

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

    /**
     * "Live" rows — the user is signed in and the row is awaiting
     * (or has received) a BDApps verdict. Replaces `scopeActive()`
     * from the old `registered`-based model. Use this for "is the
     * user currently paying us?" checks.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Backwards-compatible alias for the old `registered` semantics.
     * Kept so external callers (and older code paths) keep working
     * during the migration window. New code should call `isLive()`.
     *
     * @deprecated Use isLive() instead.
     */
    public function isActive(): bool
    {
        return $this->isLive();
    }
}
