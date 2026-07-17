<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Robi BDApps Gateway (Robi Axiata, Bangladesh)
    |--------------------------------------------------------------------------
    |
    | Configuration for the Robi/BDApps SMS / subscription gateway. Credentials
    | are issued when an application is provisioned on developer.bdapps.com.
    |
    | Sandbox vs production is selected purely by `base_url` — the API
    | surface is identical. Rotate credentials via .env without redeploys.
    |
    */

    'base_url' => env('BDAPPS_BASE_URL', 'https://developer.bdapps.com'),

    'application_id' => env('BDAPPS_APPLICATION_ID', ''),

    // Encoded hash from the BDApps dashboard. Treat as a secret.
    'password' => env('BDAPPS_PASSWORD', ''),

    // Optional. Sent with otp/request so BDApps can match incoming OTPs
    // to a specific mobile app build. Not required for backend-driven flows.
    'application_hash' => env('BDAPPS_APPLICATION_HASH', 'ChatApp'),

    'subscription_endpoint' => env('BDAPPS_SUBSCRIPTION_ENDPOINT', '/subscription/send'),

    'otp_request_endpoint' => env('BDAPPS_OTP_REQUEST_ENDPOINT', '/subscription/otp/request'),

    'otp_verify_endpoint' => env('BDAPPS_OTP_VERIFY_ENDPOINT', '/subscription/otp/verify'),

    'status_endpoint' => env('BDAPPS_STATUS_ENDPOINT', '/subscription/getStatus'),

    // POST /sms/send — Mobile-Terminated SMS outbound. Used for the
    // milestone ping ("You've sent 5 chats today. Keep going!") and
    // any other fan-out we add later.
    'sms_send_endpoint' => env('BDAPPS_SMS_SEND_ENDPOINT', '/sms/send'),

    // Default sender address (e.g. "tel:8801812345678"). When null the
    // gateway uses the alias configured in the SLA. Override with
    // BDAPPS_SMS_SOURCE_ADDRESS when you need a specific source.
    'sms_source_address' => env('BDAPPS_SMS_SOURCE_ADDRESS', null),

    // Request SMS Delivery Status Reports. Default off — we log every
    // send locally to the bdapps channel anyway, the gateway DR adds
    // another inbound webhook to maintain.
    'sms_delivery_status_request' => env('BDAPPS_SMS_DELIVERY_STATUS', '0'),

    // Encoding scheme for SMS payloads. 0 = plain text (default),
    // 240 = flash, 245 = binary.
    'sms_encoding' => env('BDAPPS_SMS_ENCODING', '0'),

    'timeout_seconds' => (int) env('BDAPPS_TIMEOUT', 30),

    'verify_ssl' => filter_var(env('BDAPPS_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),

    // Country code prepended when normalising local MSISDNs into the
    // `tel:<country><number>` form BDApps requires.
    'country_code' => env('BDAPPS_COUNTRY_CODE', '880'),

    // BDApps success status code. Anything else is treated as an error.
    'success_status_code' => env('BDAPPS_SUCCESS_STATUS_CODE', 'S1000'),

    // Webhook shared secret. Used by /api/webhooks/bdapps/notify to
    // authenticate incoming BDApps notifications via constant-time compare.
    'notify_secret' => env('BDAPPS_NOTIFY_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Feature flags (read via config() at runtime, never env() directly)
    |--------------------------------------------------------------------------
    |
    | Feature flags live here so they're cached in the config repository.
    | Calling env() outside the config loading phase returns null in
    | production (Laravel clears the env between requests once config is
    | cached), which makes every feature-flag gate silently false. Always
    | read these via config('bdapps.*').
    |
    */

    // Send a courtesy SMS when an already-trusted user (subscribed or
    // pending) skips OTP and signs in directly. Defaults off — flip to
    // true in production once the /sms/send path is wired in the gateway.
    'login_sms_notify_enabled' => filter_var(
        env('BDAPPS_LOGIN_SMS_NOTIFY_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN
    ),

    // Fire a milestone SMS via /sms/send every time a user completes
    // their 5th, 10th, 15th… AI chat. Defaults off — enable in prod.
    'milestone_sms_enabled' => filter_var(
        env('CHAT_MILESTONE_SMS_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN
    ),
];
