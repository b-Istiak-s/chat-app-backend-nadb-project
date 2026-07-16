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
];
