<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\BdApps\SubscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Polls the BDApps gateway once for a freshly-verified user and
 * finalizes their subscription state. Dispatched with a 10-second
 * delay (configurable via `bdapps.delayed_getstatus_seconds`) from
 * `SubscriptionService::finalizeActivation()` callers — primarily
 * the OTP-verify path and the dashboard's "Refresh now" action.
 *
 * The job is the canonical post-verify reconciliation path. The
 * legacy `bdapps:poll-pending` cron remains as a safety net for
 * rows the worker missed (e.g. worker down at the moment the job
 * was scheduled).
 *
 * If the user no longer exists (deleted while the job was queued),
 * the job exits silently — there is nothing to reconcile.
 */
class PollSubscriptionStatusJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Three attempts, with growing backoff. The cron is the backstop. */
    public int $tries = 3;

    /** Each attempt is allowed to run for up to 60s. */
    public int $timeout = 60;

    /**
     * @param  int  $userId  The User row to finalize. Captured by
     *                        value (not as a hydrated Eloquent model)
     *                        so the dispatcher is not tied to a
     *                        specific in-memory instance.
     */
    public function __construct(
        public readonly int $userId,
    ) {}

    /**
     * Backoff between retries, in seconds. The cron safety net
     * picks up the slack if all three attempts fail.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(SubscriptionService $service): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $service->finalizeActivation($user);

        Log::channel('bdapps')->info('bdapps.finalize_activation_job', [
            'user_id' => $user->id,
            'phone' => $user->phone,
            'subscription_status' => $user->subscription_status,
            'attempts' => $this->attempts(),
        ]);
    }

    /**
     * Last-resort failure handler — log structured fields, do not
     * crash the worker. The cron safety net (`bdapps:poll-pending`)
     * will reconcile the same row on its next 5-minute run.
     */
    public function failed(\Throwable $e): void
    {
        Log::channel('bdapps')->error('bdapps.finalize_activation_job_failed', [
            'user_id' => $this->userId,
            'error' => $e->getMessage(),
        ]);
    }
}
