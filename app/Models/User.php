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

    public function isSubscribed(): bool
    {
        return $this->subscription_status === 'subscribed';
    }
}