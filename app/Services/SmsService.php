<?php

namespace App\Services;

use App\Exceptions\BdApps\BdAppsException;
use App\Models\ChatMilestone;
use App\Models\User;
use App\Services\BdApps\BdAppsService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * Sends chat-milestone SMS notifications via BDApps /sms/send.
 *
 * When a user has sent N (where N % 5 === 0 and N >= 5) AI chats in
 * their lifetime, we send a "You've sent N chats today. Keep going!"
 * encouragement via BDApps. Each milestone is recorded in
 * `chat_milestones` (user_id, count unique) so we never double-send
 * even if the SMS API is retried.
 *
 * The actual transport work lives in `BdAppsService::sendSms`; this
 * class owns **when** to send and **what** to record so the chat
 * flow stays free of milestone bookkeeping.
 */
class SmsService
{
    /** Milestones fire at every multiple of this number, including 5. */
    public const STEP = 5;

    /**
     * Minimum count required for any milestone to fire. Below this we
     * stay silent. Set deliberately higher than 0 so a user has
     * actually engaged before we use up their phone's attention.
     */
    public const MIN_MILESTONE = 5;

    public function __construct(
        private BdAppsService $bdApps,
    ) {}

    /**
     * Decide whether the user just crossed a milestone and, if so,
     * send the notification. Idempotent: re-running for the same N is
     * a no-op (the unique index on chat_milestones rejects repeats).
     */
    public function maybeNotifyMilestone(User $user, int $assistantTurns): void
    {
        if (! $this->isMilestone($assistantTurns)) {
            return;
        }

        // Feature flag: opt-in via env so tests / local dev aren't
        // accidentally sending real SMS to developer phones.
        if (! filter_var(env('CHAT_MILESTONE_SMS_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $message = sprintf(
            "You've sent %d chats today. Keep going!",
            $assistantTurns,
        );

        // Prefer the gateway's own base64 subscriberId (set on the row
        // after the verify call). The gateway docs flag sending back
        // a locally-derived `tel:880…` form as a mismatch — using the
        // gateway-canonical form keeps /sms/send symmetric with the
        // /subscription/* endpoints. Fall back to phone if we don't
        // have it (e.g. milestone fires before verify completes).
        $gatewaySubscriberId = $user->bdappsSubscriptions()
            ->orderByDesc('id')
            ->value('gateway_subscriber_id');

        try {
            $result = $this->bdApps->sendSms(
                $user->phone,
                $message,
                gatewaySubscriberId: $gatewaySubscriberId,
            );
        } catch (BdAppsException $e) {
            Log::channel('bdapps')->warning('bdapps.milestone_sms_failed', [
                'user_id' => $user->id,
                'phone' => $user->phone,
                'count' => $assistantTurns,
                'status_code' => $e->statusCode,
                'status_detail' => $e->statusDetail,
            ]);

            return;
        } catch (ConnectionException $e) {
            Log::channel('bdapps')->warning('bdapps.milestone_sms_failed', [
                'user_id' => $user->id,
                'phone' => $user->phone,
                'count' => $assistantTurns,
                'transport_error' => $e->getMessage(),
            ]);

            return;
        }

        // Record the milestone. unique(user_id, count) makes this
        // idempotent — a repeat (e.g. due to a retry) silently no-ops.
        try {
            ChatMilestone::create([
                'user_id' => $user->id,
                'count' => $assistantTurns,
                'sent_at' => now(),
                'sms_request_id' => $result['request_id'] ?? null,
                'sms_status_code' => $result['first_destination_status'] ?? $result['status_code'] ?? null,
            ]);
        } catch (QueryException $e) {
            // Duplicate (user_id, count). That's fine — we logged
            // nothing because we don't want double-SMS in logs.
            Log::channel('bdapps')->info('bdapps.milestone_sms_duplicate', [
                'user_id' => $user->id,
                'count' => $assistantTurns,
            ]);
        }
    }

    /**
     * True when the integer represents a milestone we care about:
     * any positive multiple of `STEP` at or above `MIN_MILESTONE`.
     */
    public function isMilestone(int $count): bool
    {
        return $count >= self::MIN_MILESTONE
            && $count % self::STEP === 0;
    }
}
