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
     * Token-bearing user. In the new three-state model this means
     * the user's `subscription_status` is `pending` — the OTP was
     * verified by us and they are signed in. The user MAY also have
     * a BDApps `REGISTERED` mirror (in which case they have full
     * access), or a PENDING-family mirror (in which case the app
     * shows the "Payment pending" page).
     */
    public function isVerified(): bool
    {
        return $this->subscription_status === 'pending';
    }

    /**
     * User has not yet entered the OTP (or their previous session
     * was cancelled). They cannot get a Sanctum token; the next
     * `/api/auth/start` will request a fresh OTP.
     */
    public function isAwaitingOtp(): bool
    {
        return $this->subscription_status === 'unverified';
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
     * The user's most recent subscription row — used by the
     * dashboard and the app router to read the gateway mirror
     * (`bdapps_subscription_status`) and decide whether to render
     * chat or the "Payment pending" page.
     */
    public function latestSubscription(): ?BdappsSubscription
    {
        return $this->bdappsSubscriptions()->orderByDesc('id')->first();
    }

    /**
     * True when the user is verified (`pending`) AND the latest
     * gateway mirror is non-`REGISTERED` — i.e. BDApps is still
     * mid-charge. The dashboard renders the "Payment pending" view
     * for this case. Replaces the old `isPaymentPending()` helper
     * from the previous model.
     */
    public function hasPendingCharge(): bool
    {
        if (! $this->isVerified()) {
            return false;
        }

        $mirror = (string) ($this->latestSubscription()?->bdapps_subscription_status ?? '');

        return $mirror !== '' && $mirror !== 'REGISTERED';
    }

    /**
     * Backwards-compatible alias for `isVerified()`. Kept during
     * the migration window so existing call sites keep working.
     *
     * @deprecated Use isVerified() instead.
     */
    public function isSubscribed(): bool
    {
        return $this->isVerified();
    }

    /**
     * Backwards-compatible alias for `hasPendingCharge()`. Kept
     * during the migration window.
     *
     * @deprecated Use hasPendingCharge() instead.
     */
    public function isPaymentPending(): bool
    {
        return $this->hasPendingCharge();
    }
}
