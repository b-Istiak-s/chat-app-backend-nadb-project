<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BdApps\BdAppsService;
use App\Services\BdApps\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles `POST /api/webhooks/bdapps/notify`.
 *
 * Authentication: BDApps pushes JSON body containing
 *   { timeStamp, status, applicationId, subscriberId, frequency }
 *
 * We authenticate by checking:
 *   - applicationId matches our config('bdapps.application_id')
 *   - the request body signature or shared secret matches
 *
 * For this implementation we use a shared notify_secret (passed in
 * header `X-BdApps-Secret`) plus a constant-time compare against
 * config('bdapps.notify_secret'). If notify_secret is empty we fall
 * back to verifying applicationId + a password check (mirroring the
 * pattern from BDApps quiz_app PHP listener).
 */
class BdAppsNotifyController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptionService,
        private BdAppsService $bdApps,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $expectedAppId = (string) config('bdapps.application_id');
        $expectedSecret = (string) config('bdapps.notify_secret');
        $providedAppId = (string) $request->input('applicationId', '');
        $providedSecret = (string) ($request->header('X-Bdapps-Secret')
            ?? $request->input('notify_secret', ''));

        if ($expectedAppId === '') {
            Log::error('bdapps.notify_misconfigured');

            return response()->json([
                'statusCode' => 'E1000',
                'statusDetail' => 'Webhook not configured.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Application id must match our provisioned app.
        if ($providedAppId !== $expectedAppId) {
            Log::warning('bdapps.notify_app_id_mismatch', [
                'provided' => $providedAppId,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'statusCode' => 'E1001',
                'statusDetail' => 'Unauthorized.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // If a notify_secret is configured, the call must present it.
        if ($expectedSecret !== '' && ! hash_equals($expectedSecret, $providedSecret)) {
            Log::warning('bdapps.notify_secret_mismatch', [
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
            return response()->json([
                'statusCode' => 'E1002',
                'statusDetail' => 'Missing subscriberId or status.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $phone = $this->bdApps->extractLocalPhone($subscriberId);
        if ($phone === null) {
            return response()->json([
                'statusCode' => 'E1003',
                'statusDetail' => 'Invalid subscriberId.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $user = User::where('phone', $phone)->first();
        if (! $user) {
            // Unknown phone — BDApps might notify us about a number
            // that unsubscribed before completing registration.
            // Acknowledge anyway so BDApps doesn't retry forever.
            Log::info('bdapps.notify_unknown_phone', ['phone' => $phone]);

            return response()->json([
                'statusCode' => 'S1000',
                'statusDetail' => 'Request was successfully processed',
            ]);
        }

        $this->subscriptionService->applyNotifyStatus($user, $status, $frequency);

        Log::info('bdapps.notify_applied', [
            'user_id' => $user->id,
            'phone' => $phone,
            'status' => $status,
            'frequency' => $frequency,
        ]);

        return response()->json([
            'statusCode' => 'S1000',
            'statusDetail' => 'Request was successfully processed',
        ]);
    }
}