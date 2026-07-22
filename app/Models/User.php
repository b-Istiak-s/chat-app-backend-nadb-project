<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['phone', 'subscription_status', 'subscribed_at', 'phone_verified_at'])]
#[Hidden(['remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'subscribed_at' => 'datetime',
        ];
    }

    public function bdappsSubscriptions(): HasMany
    {
        return $this->hasMany(BdappsSubscription::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(ChatConversation::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    /**
     * Phone is confirmed to belong to this user — i.e. the first
     * OTP was entered successfully. **Decoupled from subscription
     * state**: a verified user can still be `unverified` /
     * `pending` / `registered` / `cancelled` from a subscription
     * perspective. The stamp lives on `phone_verified_at` and is
     * the source of truth for "is this number owned by this
     * user?".
     */
    public function isVerified(): bool
    {
        return $this->phone_verified_at !== null;
    }

    /**
     * Subscription state: OTP not yet entered. No token. Next
     * `/api/auth/start` requests a fresh OTP.
     */
    public function isAwaitingOtp(): bool
    {
        return $this->subscription_status === 'unverified';
    }

    /**
     * Subscription state: OTP verified by us, BDApps is still
     * charging. The user has a token but feature access is
     * locked — the app shows the "Payment pending" page until
     * the row flips to `registered`.
     */
    public function isSubscriptionPending(): bool
    {
        return $this->subscription_status === 'pending';
    }

    /**
     * Subscription state: BDApps confirmed `REGISTERED`, money
     * taken. The user has a token AND full feature access (chat,
     * APK download).
     */
    public function isRegistered(): bool
    {
        return $this->subscription_status === 'registered';
    }

    /**
     * True when the user holds a token — covers both `pending`
     * (mid-charge) and `registered` (fully active). This is the
     * "should `/api/auth/start` short-circuit without sending an
     * OTP?" check used by `AuthController::userHasValidToken()`.
     */
    public function isTokenBearing(): bool
    {
        return $this->isSubscriptionPending() || $this->isRegistered();
    }

    /**
     * Terminal: user cancelled, or the gateway returned a
     * non-`REGISTERED` terminal status. No token; the next login
     * starts a fresh OTP.
     */
    public function isCancelled(): bool
    {
        return $this->subscription_status === 'cancelled';
    }

    /**
     * True when the user has full feature access — i.e. they are
     * `registered`. The dashboard / router / `downloadApk()` gate
     * all use this.
     */
    public function isFullySubscribed(): bool
    {
        return $this->isRegistered();
    }

    /**
     * The user's most recent subscription row. Used by the
     * dashboard view to read the gateway mirror column
     * (`bdapps_subscription_status`) for display only — the
     * mirror is no longer the source of truth for feature gating.
     */
    public function latestSubscription(): ?BdappsSubscription
    {
        return $this->bdappsSubscriptions()->orderByDesc('id')->first();
    }

    /**
     * Backwards-compatible alias for `isRegistered()`. The old
     * model conflated "subscribed" with "token-bearing"; now
     * `subscribed` means "fully subscribed" (i.e. registered).
     * Kept during the migration window so existing call sites
     * compile.
     *
     * @deprecated Use isRegistered() instead.
     */
    public function isSubscribed(): bool
    {
        return $this->isRegistered();
    }
}
