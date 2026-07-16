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
    public function __construct() {}

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
        $this->logCall('otp.request', $payload, $body, $httpStatus);

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
        $this->logCall('otp.verify', $payload, $body, $httpStatus);

        return $this->finalize('otp verify', [
            'ok' => $response->successful() && ! $this->isError($body),
            'http_status' => $httpStatus,
            'subscription_status' => $body['subscriptionStatus'] ?? null,
            'status_code' => $body['statusCode'] ?? null,
            'status_detail' => $body['statusDetail'] ?? null,
            'raw' => $body,
        ], $httpStatus);
    }

    /**
     * `POST /subscription/getStatus` — used to reconcile local state
     * with whatever BDApps currently thinks (e.g. user replied STOP to
     * the welcome SMS since we last heard from them).
     */
    public function getStatus(string $phone): array
    {
        $payload = [
            'applicationId' => config('bdapps.application_id'),
            'password' => config('bdapps.password'),
            'subscriberId' => $this->formatSubscriberId($phone),
        ];

        $response = $this->http()
            ->post(config('bdapps.base_url').config('bdapps.status_endpoint'), $payload);

        $body = $response->json() ?? [];
        $httpStatus = $response->status();
        $this->logCall('subscription.getStatus', $payload, $body, $httpStatus);

        // getStatus can return S1000 even when the user is UNREGISTERED.
        // "Errors" here are reserved for transport / auth failures, not
        // for "user is not subscribed".
        return $this->finalize('getStatus', [
            'ok' => $response->successful(),
            'http_status' => $httpStatus,
            'subscription_status' => $body['subscriptionStatus'] ?? null,
            'status_code' => $body['statusCode'] ?? null,
            'status_detail' => $body['statusDetail'] ?? null,
            'raw' => $body,
        ], $httpStatus);
    }

    /**
     * `POST /subscription/send` with action='0' — cancel the user's
     * subscription. Idempotent at the gateway level; a repeat call
     * returns UNREGISTERED again.
     */
    public function unsubscribe(string $phone): array
    {
        return $this->callSubscriptionSend($phone, '0');
    }

    /**
     * `POST /subscription/send` with action='1' — manual subscribe.
     * Normally the OTP flow handles subscription activation, but this
     * is useful for re-subscribing a previously cancelled user.
     */
    public function subscribe(string $phone): array
    {
        return $this->callSubscriptionSend($phone, '1');
    }

    protected function callSubscriptionSend(string $phone, string $action): array
    {
        $payload = [
            'version' => '1.0',
            'applicationId' => config('bdapps.application_id'),
            'password' => config('bdapps.password'),
            'subscriberId' => $this->formatSubscriberId($phone),
            'action' => $action,
        ];

        $response = $this->http()
            ->post(config('bdapps.base_url').config('bdapps.subscription_endpoint'), $payload);

        $body = $response->json() ?? [];
        $httpStatus = $response->status();
        $this->logCall('subscription.send', $payload, $body, $httpStatus);

        return $this->finalize('subscription '.$action, [
            'ok' => $response->successful() && ! $this->isError($body),
            'http_status' => $httpStatus,
            'subscription_status' => $body['subscriptionStatus'] ?? null,
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

    protected function logCall(string $endpoint, array $payload, array $body, int $httpStatus): void
    {
        $logPayload = $payload;
        if (isset($logPayload['password'])) {
            $logPayload['password'] = '****';
        }

        Log::channel(config('bdapps.log_channel', 'stack'))->info("bdapps.{$endpoint}", [
            'http_status' => $httpStatus,
            'request' => $logPayload,
            'response' => $body,
        ]);
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
