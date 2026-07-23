<?php

namespace App\Services\BdApps;

use App\Exceptions\BdApps\BdAppsException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP client for the Robi BDApps gateway.
 *
 * Reference shapes are lifted from
 *   ~/projects/nadb/quiz_app/bdapps_api_php/{send_otp,verify_otp,
 *   unsubscribe,check_subscription,sdk_file}.php
 *
 * The endpoint contract:
 *   - POST /subscription/otp/request  → returns {referenceNo, statusCode, statusDetail}
 *   - POST /subscription/otp/verify   → returns {subscriptionStatus, statusCode, statusDetail}
 *   - POST /subscription/getStatus    → returns {subscriptionStatus, statusCode, statusDetail}
 *   - POST /subscription/send         → returns {subscriptionStatus, statusCode, statusDetail}
 *                                        action='1' to subscribe, action='0' to unsubscribe
 *
 * Phone numbers are normalised to `tel:<countryCode><local>` per BDApps.
 */
class BdAppsService
{
    /**
     * Gateway `subscriptionStatus` values that mean "still charging —
     * not yet REGISTERED". When the gateway returns one of these the
     * subscription is in flight; we treat it as a soft success on
     * /otp/verify (token issued) but persist status='pending' on the
     * local row so a reconciler can poll /getStatus later.
     */
    public const PENDING_STATUSES = [
        'INITIAL CHARGING PENDING',
        'CHARGE_PENDING',
        'PENDING',
    ];

    /**
     * Gateway statuses that mean the subscription has been
     * cancelled on the gateway side — `UNREGISTERED`, `EXPIRED`,
     * `TEMPORARY BLOCKED`, and any other non-`REGISTERED` /
     * non-pending-family reply.
     *
     * `TEMPORARY BLOCKED` is operator-applied (BDApps holds the
     * line: re-charge blocked for a window before retry). From
     * our side it behaves like `UNREGISTERED`: row goes to
     * `unregistered`, no token issued, next `/auth/start` will
     * see whether the gateway has unblocked yet.
     *
     * Used by the subscription lifecycle to decide whether a
     * gateway reply should move the local row + user to
     * `unregistered`. REGISTERED is the success path;
     * PENDING-family is mid-charge.
     */
    public const TERMINAL_FAILURE_STATUSES = [
        'UNREGISTERED',
        'EXPIRED',
        'TEMPORARY BLOCKED',
    ];

    public function __construct() {}

    /**
     * True when a gateway subscriptionStatus value indicates the
     * subscription is still being processed (i.e. not yet REGISTERED
     * and not yet failed). Match is case-insensitive.
     */
    public function isPendingStatus(?string $status): bool
    {
        if ($status === null) {
            return false;
        }

        $upper = strtoupper(trim($status));

        return in_array($upper, self::PENDING_STATUSES, true);
    }

    /**
     * True when a gateway-reported status means the subscription
     * has been cancelled on the BDApps side. Covers the explicit
     * `UNREGISTERED` / `EXPIRED` values plus any other
     * non-`REGISTERED`, non-pending-family reply (defensive —
     * catches future gateway variants).
     *
     * Empty/null statuses are NOT terminal — they mean "we
     * haven't heard anything from the gateway", which is
     * handled separately (the row keeps its current state).
     */
    public function isTerminalFailure(?string $status): bool
    {
        if ($status === null || $status === '') {
            return false;
        }

        $upper = strtoupper(trim($status));

        if (in_array($upper, self::TERMINAL_FAILURE_STATUSES, true)) {
            return true;
        }

        // Defensive: any other known-cancellation string from
        // the gateway is also terminal. REGISTERED is success,
        // PENDING-family is mid-charge, anything else is
        // treated as cancellation.
        return $upper !== 'REGISTERED'
            && ! in_array($upper, self::PENDING_STATUSES, true);
    }

    /**
     * True when the gateway returned the operator-applied
     * `TEMPORARY BLOCKED` status. Distinct from a clean
     * `UNREGISTERED` because the line is expected to unblock
     * — calling this out separately lets the UI / logs surface
     * "subscription is paused by the operator, try again later"
     * instead of a generic "subscription cancelled" copy.
     *
     * Match is case-insensitive. Null/empty returns false.
     */
    public function isTemporaryBlocked(?string $status): bool
    {
        if ($status === null || $status === '') {
            return false;
        }

        return strtoupper(trim($status)) === 'TEMPORARY BLOCKED';
    }

    /**
     * `POST /subscription/otp/request` — BDApps generates a one-time code
     * and sends it to the user via SMS; we must persist the returned
     * referenceNo for the matching verify call.
     */
    public function requestOtp(string $phone): array
    {
        $payload = [
            'applicationId' => config('bdapps.application_id'),
            'password' => config('bdapps.password'),
            'subscriberId' => $this->formatSubscriberId($phone),
            'applicationHash' => config('bdapps.application_hash'),
            'applicationMetaData' => [
                'client' => 'MOBILEAPP',
                'device' => 'Flutter',
                'os' => 'android 14',
                'appCode' => config('app.url'),
            ],
        ];

        $response = $this->http()
            ->post(config('bdapps.base_url').config('bdapps.otp_request_endpoint'), $payload);

        $body = $response->json() ?? [];
        $httpStatus = $response->status();

        $logPayload = $payload;
        $logPayload['password'] = '****';
        Log::channel('bdapps')->info('bdapps.otp.request', [
            'http_status' => $httpStatus,
            'request' => $logPayload,
            'response' => $body,
        ]);

        return $this->finalize('otp request', [
            'ok' => $response->successful() && ! $this->isError($body),
            'http_status' => $httpStatus,
            'reference_no' => $body['referenceNo'] ?? null,
            'status_code' => $body['statusCode'] ?? null,
            'status_detail' => $body['statusDetail'] ?? null,
            'raw' => $body,
        ], $httpStatus);
    }

    /**
     * `POST /subscription/otp/verify` — on success BDApps returns
     * subscriptionStatus: REGISTERED, which is what flips the user
     * from `pending` to fully active on our side.
     */
    public function verifyOtp(string $referenceNo, string $otp): array
    {
        $payload = [
            'applicationId' => config('bdapps.application_id'),
            'password' => config('bdapps.password'),
            'referenceNo' => $referenceNo,
            'otp' => $otp,
        ];

        $response = $this->http()
            ->post(config('bdapps.base_url').config('bdapps.otp_verify_endpoint'), $payload);

        $body = $response->json() ?? [];
        $httpStatus = $response->status();

        $logPayload = $payload;
        $logPayload['password'] = '****';
        Log::channel('bdapps')->info('bdapps.otp.verify', [
            'http_status' => $httpStatus,
            'request' => $logPayload,
            'response' => $body,
        ]);

        return $this->finalize('otp verify', [
            'ok' => $response->successful() && ! $this->isError($body),
            'http_status' => $httpStatus,
            'subscription_status' => $body['subscriptionStatus'] ?? null,
            'gateway_subscriber_id' => $body['subscriberId'] ?? null,
            'status_code' => $body['statusCode'] ?? null,
            'status_detail' => $body['statusDetail'] ?? null,
            'raw' => $body,
        ], $httpStatus);
    }

    /**
     * `POST /subscription/getStatus` — used to reconcile local state
     * with whatever BDApps currently thinks (e.g. user replied STOP to
     * the welcome SMS since we last heard from them).
     *
     * Pass the gateway-canonical base64 `subscriberId` via the second
     * argument when we have one (persisted on the subscription row as
     * `gateway_subscriber_id`). Falls back to the normalised
     * `tel:880…` form derived from `$phone` otherwise.
     */
    public function getStatus(string $phone, ?string $gatewaySubscriberId = null): array
    {
        $payload = [
            'applicationId' => config('bdapps.application_id'),
            'password' => config('bdapps.password'),
            'subscriberId' => $gatewaySubscriberId ?? $this->formatSubscriberId($phone),
        ];

        $response = $this->http()
            ->post(config('bdapps.base_url').config('bdapps.status_endpoint'), $payload);

        $body = $response->json() ?? [];
        $httpStatus = $response->status();

        $logPayload = $payload;
        $logPayload['password'] = '****';
        Log::channel('bdapps')->info('bdapps.subscription.getStatus', [
            'http_status' => $httpStatus,
            'request' => $logPayload,
            'response' => $body,
        ]);

        // getStatus can return S1000 even when the user is UNREGISTERED.
        // "Errors" here are reserved for transport / auth failures, not
        // for "user is not subscribed".
        return $this->finalize('getStatus', [
            'ok' => $response->successful(),
            'http_status' => $httpStatus,
            'subscription_status' => $body['subscriptionStatus'] ?? null,
            'gateway_subscriber_id' => $body['subscriberId'] ?? null,
            'status_code' => $body['statusCode'] ?? null,
            'status_detail' => $body['statusDetail'] ?? null,
            'raw' => $body,
        ], $httpStatus);
    }

    /**
     * `POST /subscription/send` with action='0' — cancel the user's
     * subscription. Idempotent at the gateway level; a repeat call
     * returns UNREGISTERED again.
     *
     * Pass the gateway-canonical base64 `subscriberId` via the second
     * argument when we have it (persisted on the subscription row as
     * `gateway_subscriber_id`). Falls back to the normalised
     * `tel:880…` form derived from `$phone` otherwise.
     */
    public function unsubscribe(string $phone, ?string $gatewaySubscriberId = null): array
    {
        return $this->callSubscriptionSend($phone, '0', $gatewaySubscriberId);
    }

    /**
     * `POST /subscription/send` with action='1' — manual subscribe.
     * Normally the OTP flow handles subscription activation, but this
     * is useful for re-subscribing a previously cancelled user.
     */
    public function subscribe(string $phone, ?string $gatewaySubscriberId = null): array
    {
        return $this->callSubscriptionSend($phone, '1', $gatewaySubscriberId);
    }

    /**
     * `POST /sms/send` — Mobile-Terminated SMS.
     *
     * Sends a plain-text SMS to `$phone` (normalised via
     * `formatSubscriberId()` to `tel:880…`). Used for milestone pings
     * (every 5 AI chats) and any other fan-out notifications. The
     * destination address is always a concrete phone number — we do
     * NOT use `tel: all` (broadcast to the whole subscribed base) from
     * this code path.
     *
     * If `$gatewaySubscriberId` is supplied, it is used as the
     * destination verbatim — the gateway treats its own base64-encoded
     * `subscriberId` (returned from verify / getStatus / notify) as
     * canonical. Locally-derived `tel:880…` forms are accepted in
     * practice but flag a mismatch in the gateway docs. Prefer
     * passing the persisted `bdapps_subscriptions.gateway_subscriber_id`
     * for any user that has one (callers look it up from the user's
     * latest subscription row).
     *
     * Returns the same shape as the other gateway helpers:
     *   - `ok`        — true on HTTP 200 + non-error status code
     *   - `http_status`
     *   - `request_id` — gateway `requestId` from the response
     *   - `destination_responses` — per-address delivery response array
     *   - `status_code`, `status_detail`, `raw`
     *
     * Throws `BdAppsException` on non-success — callers (`SmsService`)
     * catch and log to the bdapps channel.
     */
    public function sendSms(
        string $phone,
        string $message,
        ?string $sourceAddress = null,
        ?string $encoding = null,
        ?string $deliveryStatusRequest = null,
        ?string $gatewaySubscriberId = null,
    ): array {
        $destination = $gatewaySubscriberId !== null && $gatewaySubscriberId !== ''
            ? $gatewaySubscriberId
            : $this->formatSubscriberId($phone);

        $payload = [
            'version' => '1.0',
            'applicationId' => config('bdapps.application_id'),
            'password' => config('bdapps.password'),
            'message' => $message,
            'destinationAddresses' => [$destination],
        ];

        // `sourceAddress` is optional (the gateway may fall back to the
        // SLA-configured alias), so only set it if we actually have a
        // value. This keeps the payload minimal by default.
        if ($sourceAddress !== null && $sourceAddress !== '') {
            $payload['sourceAddress'] = $sourceAddress;
        } else {
            $configuredSource = config('bdapps.sms_source_address');
            if (is_string($configuredSource) && $configuredSource !== '') {
                $payload['sourceAddress'] = $configuredSource;
            }
        }

        $payload['deliveryStatusRequest'] = $deliveryStatusRequest
            ?? (string) config('bdapps.sms_delivery_status_request', '0');

        $payload['encoding'] = $encoding
            ?? (string) config('bdapps.sms_encoding', '0');

        $response = $this->http()
            ->post(config('bdapps.base_url').config('bdapps.sms_send_endpoint'), $payload);

        $body = $response->json() ?? [];
        $httpStatus = $response->status();

        $logPayload = $payload;
        $logPayload['password'] = '****';
        Log::channel('bdapps')->info('bdapps.sms.send', [
            'http_status' => $httpStatus,
            'request' => $logPayload,
            'response' => $body,
        ]);

        $destinations = $body['destinationResponses'] ?? [];
        $firstDestination = is_array($destinations) && ! empty($destinations)
            ? $destinations[0]
            : null;

        return $this->finalize('sms send', [
            'ok' => $response->successful() && ! $this->isError($body),
            'http_status' => $httpStatus,
            'request_id' => $body['requestId'] ?? null,
            'destination_responses' => $destinations,
            'first_message_id' => is_array($firstDestination)
                ? ($firstDestination['messageId'] ?? null)
                : null,
            'first_destination_status' => is_array($firstDestination)
                ? ($firstDestination['statusCode'] ?? null)
                : null,
            'status_code' => $body['statusCode'] ?? null,
            'status_detail' => $body['statusDetail'] ?? null,
            'raw' => $body,
        ], $httpStatus);
    }

    protected function callSubscriptionSend(string $phone, string $action, ?string $gatewaySubscriberId = null): array
    {
        $payload = [
            'version' => '1.0',
            'applicationId' => config('bdapps.application_id'),
            'password' => config('bdapps.password'),
            'subscriberId' => $gatewaySubscriberId ?? $this->formatSubscriberId($phone),
            'action' => $action,
        ];

        $response = $this->http()
            ->post(config('bdapps.base_url').config('bdapps.subscription_endpoint'), $payload);

        $body = $response->json() ?? [];
        $httpStatus = $response->status();

        $logPayload = $payload;
        $logPayload['password'] = '****';
        Log::channel('bdapps')->info('bdapps.subscription.send', [
            'http_status' => $httpStatus,
            'request' => $logPayload,
            'response' => $body,
        ]);

        return $this->finalize('subscription '.$action, [
            'ok' => $response->successful() && ! $this->isError($body),
            'http_status' => $httpStatus,
            'subscription_status' => $body['subscriptionStatus'] ?? null,
            'gateway_subscriber_id' => $body['subscriberId'] ?? null,
            'status_code' => $body['statusCode'] ?? null,
            'status_detail' => $body['statusDetail'] ?? null,
            'raw' => $body,
        ], $httpStatus);
    }

    /**
     * Normalise a phone number into the `tel:<country><number>` form
     * BDApps requires. Accepts:
     *   - `018XXXXXXXX` (local Bangladesh) → `tel:88018XXXXXXXX`
     *   - `88018XXXXXXXX`                 → `tel:88018XXXXXXXX`
     *   - `8818XXXXXXXX`                  → `tel:88018XXXXXXXX`
     *   - `tel:88018XXXXXXXX`             → returned as-is
     */
    public function formatSubscriberId(string $phone): string
    {
        $phone = trim($phone);
        if (str_starts_with($phone, 'tel:')) {
            return $phone;
        }

        $country = (string) config('bdapps.country_code', '880');
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        // 880 + 0 + 10 digits (14 digits total) — international with leading 0.
        // e.g. "8801812345678" → "tel:8801812345678".
        if (str_starts_with($digits, $country.'0') && strlen($digits) === strlen($country) + 11) {
            return 'tel:'.$digits;
        }

        // 880 + 10 digits without leading 0 (13 digits total).
        // e.g. "8801812345678" (without leading 0, would be 13 digits if we
        // counted 880 + 10 numbers). Actually this case is the same length
        // as the previous because both have 880 followed by 10 digits;
        // the difference is whether the original included a leading 0.
        // Since we already stripped non-digits, both shapes become
        // identical here, so they collapse into the same branch.
        if (str_starts_with($digits, $country) && strlen($digits) === 13) {
            return 'tel:'.$digits;
        }

        // 88 + 10 digits without leading 0 (12 digits total) — "881812345678"
        // → "tel:88" + "0" + "1812345678" = "tel:8801812345678".
        if (str_starts_with($digits, '88') && strlen($digits) === 12) {
            return 'tel:'.$country.substr($digits, 2);
        }

        // Local 0XXXXXXXXXX (11 digits) — strip the 0, then prepend the country code.
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return 'tel:'.$country.substr($digits, 1);
        }

        // Fallback.
        return 'tel:'.$country.$digits;
    }

    /**
     * Extract the local 11-digit phone from a `tel:880XXXXXXXXXX`
     * subscriber id, returning it in `018XXXXXXXX` form. Returns null
     * if the format is unrecognised.
     */
    public function extractLocalPhone(string $subscriberId): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', $subscriberId) ?? '';
        if ($digits === '') {
            return null;
        }

        $country = (string) config('bdapps.country_code', '880');
        if (str_starts_with($digits, $country) && strlen($digits) === strlen($country) + 10) {
            // 880 + 10 digits without leading 0
            return '0'.substr($digits, strlen($country));
        }

        if (str_starts_with($digits, $country) && strlen($digits) === strlen($country) + 11) {
            // 880 + 0 + 10 digits (e.g. tel:88018xxxxxxxxxx, 14 digits after stripping `tel:`)
            $rest = substr($digits, strlen($country));
            if (str_starts_with($rest, '0')) {
                return $rest;
            }

            return '0'.$rest;
        }

        return $digits;
    }

    protected function http(): PendingRequest
    {
        $request = Http::timeout((int) config('bdapps.timeout_seconds', 30))
            ->acceptJson()
            ->asJson();

        if (! config('bdapps.verify_ssl', true)) {
            $request = $request->withoutVerifying();
        }

        return $request;
    }

    protected function isError(array $body): bool
    {
        $code = $body['statusCode'] ?? null;
        if ($code === null) {
            return false; // No statusCode field — let HTTP status decide.
        }

        return $code !== (string) config('bdapps.success_status_code', 'S1000');
    }

    /**
     * Gateway-facing success gate. Returns the parsed array untouched
     * when the gateway said `ok`, otherwise throws a BdAppsException
     * carrying the gateway's statusCode/statusDetail + our HTTP status
     * so callers can log structured fields without re-parsing.
     */
    protected function finalize(string $op, array $result, int $httpStatus): array
    {
        if ($result['ok']) {
            return $result;
        }

        throw new BdAppsException(
            "BDApps {$op} failed",
            statusCode: $result['status_code'] ?? null,
            statusDetail: $result['status_detail'] ?? null,
            httpStatus: $httpStatus,
        );
    }
}
