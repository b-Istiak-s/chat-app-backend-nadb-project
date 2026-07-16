# BDApps — POST /subscription/otp/request

Source: `~/projects/nadb/quiz_app/bdapps_api_php/send_otp.php`

Triggers Robi BDApps to send an SMS OTP to the given subscriber.
Returns a `referenceNo` that **must** be persisted and passed to
`/subscription/otp/verify`.

| field | type | required | description |
|---|---|---|---|
| applicationId | string | yes | App id issued by BDApps (e.g. `APP_137539`) |
| password | string | yes | Encoded app hash from BDApps dashboard |
| subscriberId | string | yes | `tel:880XXXXXXXXXX` (14 chars after `tel:`) |
| applicationHash | string | yes | Hash binding OTPs to this mobile app build |
| applicationMetaData.client | string | no | E.g. `MOBILEAPP` |
| applicationMetaData.device | string | no | E.g. `Samsung S10` |
| applicationMetaData.os | string | no | E.g. `android 8` |
| applicationMetaData.appCode | string | no | E.g. `https://play.google.com/store/apps/details?id=...` |

## Sample request

```curl
curl \
  -X POST \
  "https://developer.bdapps.com/subscription/otp/request" \
  -H "Content-Type: application/json" \
  -d '{
    "applicationId": "APP_137539",
    "password": "<BDAPPS_PASSWORD>",
    "subscriberId": "tel:8801812345678",
    "applicationHash": "App Name",
    "applicationMetaData": {
      "client": "MOBILEAPP",
      "device": "Samsung S10",
      "os": "android 8",
      "appCode": "https://play.google.com/store/apps/details?id=lk.dialog.megarunlor"
    }
  }'
```

## Sample success response (HTTP 200)

```json
{
  "referenceNo": "REF-12345",
  "statusCode": "S1000",
  "statusDetail": "Process completed successfully.",
  "version": "1.0"
}
```

## Sample error response

```json
{
  "statusCode": "E1312",
  "statusDetail": "Request is Invalid.",
  "version": "1.0"
}
```

## Status code matrix

| statusCode | meaning |
|---|---|
| `S1000` | Success — `referenceNo` returned |
| `E1312` | Request is invalid (malformed payload) |
| `E1325` | Subscriber id format is invalid |
| `E1631` | Subscriber id is not in the application's allow-list |

## Implementation

- Service: `app/Services/BdApps/BdAppsService.php` (`requestOtp()`).
- Caller: `app/Services/BdApps/SubscriptionService.php` (`startSubscription()`).
- The Laravel `Http` client is used with `Content-Type: application/json`,
  `Accept: application/json`, and a 30-second timeout (configurable
  via `BDAPPS_TIMEOUT`).

## Logging

Every outbound call is logged to the dedicated `bdapps` channel
(`storage/logs/bdapps-YYYY-MM-DD.log`, JSON formatter) as
`bdapps.otp.request` with the redacted request body
(`password` field replaced with `****`) and the parsed response body.
See `agents/api/webhooks.md` for the inbound side.