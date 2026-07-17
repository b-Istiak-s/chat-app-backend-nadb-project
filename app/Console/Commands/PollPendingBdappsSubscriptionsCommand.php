<?php

namespace App\Console\Commands;

use App\Exceptions\BdApps\BdAppsException;
use App\Repositories\BdappsSubscriptionRepository;
use App\Services\BdApps\BdAppsService;
use App\Services\BdApps\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * Reconcile local `pending` subscriptions with the BDApps gateway.
 *
 * The Robi gateway often answers `/subscription/otp/verify` with
 * `subscriptionStatus: "INITIAL CHARGING PENDING"` — meaning the user
 * has been charged but the gateway hasn't finalised registration. We
 * optimistically mark such users as subscribed on /verify (so they get
 * a Sanctum token), but keep their subscription row at
 * `status='pending'` and let this command poll /getStatus every 5
 * minutes until the gateway reports REGISTERED (or another terminal
 * state).
 *
 * Only rows with `status='pending'` are touched. Registered /
 * unregistered rows are skipped — the user's instruction was explicit:
 * "if subscribed then don't do anything".
 */
class PollPendingBdappsSubscriptionsCommand extends Command
{
    protected $signature = 'bdapps:poll-pending
        {--minutes=5 : Minimum age (in minutes) of pending rows before they are polled}';

    protected $description = 'Poll BDApps /getStatus for subscription rows stuck in pending and reconcile state.';

    public function handle(
        BdAppsService $bdApps,
        BdappsSubscriptionRepository $repo,
        SubscriptionService $subService,
    ): int {
        $minutes = max(0, (int) $this->option('minutes'));
        $cutoff = now()->subMinutes($minutes);

        $rows = $repo->pendingForPolling($cutoff);

        Log::channel('bdapps')->info('bdapps.poll_pending_scanned', [
            'cutoff' => $cutoff->toIso8601String(),
            'count' => $rows->count(),
        ]);

        $polled = 0;
        $changed = 0;
        $unchanged = 0;
        $errored = 0;

        foreach ($rows as $row) {
            $polled++;
            $user = $row->user;
            $phone = $user?->phone ?? $row->phone;
            // Prefer the base64 wire id (canonical at the gateway).
            $subscriberId = $row->gateway_subscriber_id ?: $row->subscriber_id;

            try {
                $result = $bdApps->getStatus($phone, $subscriberId);
            } catch (BdAppsException|ConnectionException $e) {
                $errored++;
                Log::channel('bdapps')->warning('bdapps.poll_pending_error', [
                    'subscription_id' => $row->id,
                    'user_id' => $user?->id,
                    'phone' => $phone,
                    'status_code' => $e instanceof BdAppsException ? $e->statusCode : null,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $status = strtoupper((string) ($result['subscription_status'] ?? ''));

            // Cache the gateway's base64 form if it came back.
            if (! empty($result['gateway_subscriber_id'])) {
                $repo->update($row->id, [
                    'gateway_subscriber_id' => $result['gateway_subscriber_id'],
                ]);
            }

            // Reuse the same mapping the inbound notify webhook uses —
            // one source of truth for REGISTERED → subscribed.
            if ($user && $status !== '' && $status !== $row->bdapps_subscription_status) {
                $subService->applyNotifyStatus($user, $status);
                $changed++;

                Log::channel('bdapps')->info('bdapps.poll_pending_status_changed', [
                    'subscription_id' => $row->id,
                    'user_id' => $user->id,
                    'phone' => $phone,
                    'from' => $row->bdapps_subscription_status,
                    'to' => $status,
                ]);
            } else {
                $unchanged++;
                Log::channel('bdapps')->info('bdapps.poll_pending_no_change', [
                    'subscription_id' => $row->id,
                    'user_id' => $user?->id,
                    'phone' => $phone,
                    'status' => $status,
                ]);
            }
        }

        $this->info(sprintf(
            'Polled %d pending subscription(s): %d changed, %d unchanged, %d errored.',
            $polled,
            $changed,
            $unchanged,
            $errored,
        ));

        return self::SUCCESS;
    }
}
