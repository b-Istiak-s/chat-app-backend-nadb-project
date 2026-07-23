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
     * The gateway is still charging — the
     * `bdapps_subscription_status` mirror column carries the
     * literal `INITIAL CHARGING PENDING` / `CHARGE_PENDING` /
     * etc. reply from BDApps. Feature access is locked to
     * `/payment-not-confirmed` until this row flips to
     * `registered`.
     */
    public const STATUS_PENDING = 'pending';

    /**
     * BDApps confirmed `REGISTERED` — money has been taken, the
     * subscription is fully active. The user has a token AND full
     * feature access (chat, APK download).
     *
     * Transition: only `applyNotifyStatus()` and the synchronous
     * `verifyOtp()` path move rows here, and only on a literal
     * `REGISTERED` reply from BDApps.
     */
    public const STATUS_REGISTERED = 'registered';

    /**
     * Terminal: user cancelled OR the gateway returned a terminal
     * non-`REGISTERED` status (`UNREGISTERED` / `EXPIRED`). No
     * token-bearing. Next login starts a fresh OTP.
     *
     * Renamed from the older `cancelled` value to align with the
     * gateway's literal `UNREGISTERED` reply.
     */
    public const STATUS_UNREGISTERED = 'unregistered';

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
     * "Live" rows — the user is signed in and holds a token.
     * Covers both mid-charge (`pending`) and fully-active
     * (`registered`) rows. Use this when checking "does this user
     * have a subscription row we should be operating on?" — e.g.
     * `SubscriptionService::startSubscription()` short-circuits on
     * a live row to skip re-OTP.
     *
     * Strict "mid-charge" filtering (e.g. the cron poll, which
     * should ignore already-paid rows) should use
     * `scopePending()` / `scopeChargeable()`.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_REGISTERED,
        ]);
    }

    /**
     * Strict mid-charge filter: only rows awaiting a BDApps verdict.
     * The cron / per-user poll uses this so that already-`registered`
     * rows don't get re-pinged.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeRegistered(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REGISTERED);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Terminal rows (user cancelled, or gateway replied
     * `UNREGISTERED` / `EXPIRED` / `TEMPORARY BLOCKED`). Use this
     * when checking "should this user have their tokens
     * cleared?" or "should we issue a token at all?".
     */
    public function scopeUnregistered(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_UNREGISTERED);
    }

    public function isLive(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_REGISTERED,
        ], true);
    }

    public function isRegistered(): bool
    {
        return $this->status === self::STATUS_REGISTERED;
    }

    public function isUnregistered(): bool
    {
        return $this->status === self::STATUS_UNREGISTERED;
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
