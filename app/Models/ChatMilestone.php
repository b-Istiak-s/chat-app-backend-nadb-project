<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $count
 * @property Carbon $sent_at
 * @property string|null $sms_request_id
 * @property string|null $sms_status_code
 */
class ChatMilestone extends Model
{
    protected $fillable = [
        'user_id',
        'count',
        'sent_at',
        'sms_request_id',
        'sms_status_code',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
