<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\BdappsSubscriptionRepository;
use App\Services\BdApps\BdAppsService;
use App\Services\BdApps\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles `POST /api/webhooks/bdapps/notify`.
 *
 * Body shape — the operational ground truth is the quiz_app PHP
 * listener at
 *   projects/nadb/quiz_app/bdapps_api_php/subscription_listener.php
 * which parses these five fields off the raw body:
 *   { timeStamp, status, applicationId, subscriberId, frequency }
 *
 * The BDApps official spec
 * (https://developer.bdapps.com/subscription/notify) lists a wider
 * set of "required" fields — `version`, `password`, plus the five
 * above — but in practice the gateway does not send `version` or
 * `password` to webhook subscribers, and the PHP reference ignores
 * them. We treat the PHP listener as canonical: only the five fields
 * it reads are required for downstream processing, and any extra
 * fields the gateway may add in the future are still captured in
 * `bdapps.notify_received.payload` for forensic visibility.
 *
 * Authentication: the gateway posts to a configured URL and trusts
 * the receiver. The PHP listener does no auth at all — it just
 * appends the payload to a log file. We mirror that posture here:
 * the only guard is an applicationId sanity check (so a misrouted
 * POST from a different app doesn't get applied to our users). There
 * is no shared-secret / signature layer; the webhook endpoint is
 * intended to live behind a firewall or a network-level ACL rather
 * than bearer auth.
 *
 * Every entry this controller writes goes to the dedicated `bdapps`
 * log channel so the inbound webhook conversation is correlated with
 * the outbound entries BdAppsService writes. The first entry —
 * `bdapps.notify_received` — fires unconditionally on every call so
 * we always have a forensic trail even when downstream checks reject
 * the payload. It is the Laravel equivalent of the PHP listener's
 * unconditional `fwrite($myfile, $date_."\n")` on every call.
 */
class BdAppsNotifyController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptionService,
        private BdAppsService $bdApps,
        private BdappsSubscriptionRepository $subscriptions,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $expectedAppId = (string) config('bdapps.application_id');
        $providedAppId = (string) $request->input('applicationId', '');

        // Always log the full inbound payload first so even auth
        // failures leave a forensic trail in the bdapps channel.
        Log::channel('bdapps')->info('bdapps.notify_received', [
            'ip' => $request->ip(),
            'headers' => [
                'content_type' => $request->header('Content-Type'),
            ],
            'payload' => $request->all(),
        ]);

        if ($expectedAppId === '') {
            Log::channel('bdapps')->error('bdapps.notify_misconfigured');

            return response()->json([
                'statusCode' => 'E1000',
                'statusDetail' => 'Webhook not configured.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Application id must match our provisioned app.
        if ($providedAppId !== $expectedAppId) {
            Log::channel('bdapps')->warning('bdapps.notify_app_id_mismatch', [
                'provided' => $providedAppId,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'statusCode' => 'E1001',
                'statusDetail' => 'Unauthorized.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $subscriberId = (string) $request->input('subscriberId', '');
        $status = strtoupper((string) $request->input('status', ''));
        $frequency = $request->input('frequency');

        if ($subscriberId === '' || $status === '') {
            Log::channel('bdapps')->warning('bdapps.notify_missing_fields', [
                'subscriberId' => $subscriberId,
                'status' => $status,
            ]);

            return response()->json([
                'statusCode' => 'E1002',
                'statusDetail' => 'Missing subscriberId or status.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $phone = $this->bdApps->extractLocalPhone($subscriberId);
        if ($phone === null) {
            Log::channel('bdapps')->warning('bdapps.notify_invalid_subscriber_id', [
                'subscriberId' => $subscriberId,
            ]);

            return response()->json([
                'statusCode' => 'E1003',
                'statusDetail' => 'Invalid subscriberId.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $user = User::where('phone', $phone)->first();
        if (! $user) {
            // No matching local user. Try resolving directly by the
            // gateway-canonical subscriberId delivered in the webhook.
            // The BDApps gateway posts the masked base64 form
            // (`Njg1OGQ2...`) WITHOUT the `tel:` prefix; that string
            // is the same `gateway_subscriber_id` we persisted from
            // the matching /otp/verify response. Extracting a local
            // phone from it via `extractLocalPhone()` would yield
            // nonsense digits (the base64 alphabet contains digits
            // too), so we use the raw value as the lookup key. When
            // the value does come prefixed, keep it as-is — the
            // repository lookup expects the exact stored form.
            $lookupKey = str_starts_with($subscriberId, 'tel:')
                ? $subscriberId
                : 'tel:'.$subscriberId;

            $subscription = $this->subscriptions->findByGatewaySubscriberId($lookupKey)
                ?? $this->subscriptions->findBySubscriberId(
                    $this->bdApps->formatSubscriberId($subscriberId)
                );

            if ($subscription) {
                $subscription->loadMissing('user');
                $user = $subscription->user;
            }
        }

        if (! $user) {
            // Unknown — BDApps might notify us about a number that
            // unsubscribed before completing registration, or a row
            // whose gateway_subscriber_id we never captured locally.
            // Acknowledge anyway so BDApps doesn't retry forever.
            Log::channel('bdapps')->info('bdapps.notify_unknown_subscriber', [
                'subscriberId' => $subscriberId,
                'extracted_phone' => $phone,
            ]);

            return response()->json([
                'statusCode' => 'S1000',
                'statusDetail' => 'Request was successfully processed',
            ]);
        }

        $this->subscriptionService->applyNotifyStatus($user, $status, $frequency);

        Log::channel('bdapps')->info('bdapps.notify_applied', [
            'user_id' => $user->id,
            'phone' => $user->phone,
            'subscriberId' => $subscriberId,
            'status' => $status,
            'frequency' => $frequency,
            'timeStamp' => $request->input('timeStamp'),
        ]);

        return response()->json([
            'statusCode' => 'S1000',
            'statusDetail' => 'Request was successfully processed',
        ]);
    }
}
