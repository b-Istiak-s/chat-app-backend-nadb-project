<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles `POST /api/webhooks/bdapps/sms` — Mobile-Originated SMS.
 *
 * The Robi BDApps gateway pushes any SMS that an end user sends to
 * the keyword `99898` (short code `21213`) to this endpoint as soon
 * as the user hits send. The body shape per the BDApps docs is:
 *
 *   {
 *     "version":       "1.0",
 *     "applicationId": "APP_137539",
 *     "sourceAddress": "tel:88018XXXXXXXX",
 *     "message":       "<user text>",
 *     "requestId":     "<gateway request id>",
 *     "encoding":      "0" | "240" | "245"
 *   }
 *
 * This is currently a **log-only** surface: we write the full payload
 * to the dedicated `bdapps` channel for forensics and acknowledge
 * `S1000` so the gateway stops retrying. No DB row, no automatic
 * reply, no phone → user lookup — the user instruction was explicit:
 * "just log that don't have to do anything".
 *
 * If we later want to react to MO keywords (e.g. STOP, BAL, HELP),
 * this is the place to add that logic. Keep the controller thin.
 */
class BdAppsSmsReceiveController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        Log::channel('bdapps')->info('bdapps.sms_received', [
            'ip' => $request->ip(),
            'headers' => [
                'content_type' => $request->header('Content-Type'),
            ],
            'payload' => $request->all(),
        ]);

        return response()->json([
            'statusCode' => 'S1000',
            'statusDetail' => 'Request was successfully processed',
        ], Response::HTTP_OK);
    }
}
