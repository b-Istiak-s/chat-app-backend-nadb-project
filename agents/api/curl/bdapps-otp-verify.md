# BDApps — POST /subscription/otp/verify

Source: `~/projects/nadb/quiz_app/bdapps_api_php/verify_otp.php`

Submits the OTP the user received via SMS. On success BDApps flips the
subscriber's status to `REGISTERED` and our local mirror follows.

| field | type | required | description |
|---|---|---|---|
| applicationId | string | yes | Same as OTP request |
| password | string | yes | Same as OTP request |
| referenceNo | string | yes | Returned by the matching `/otp/request` call |
| otp | string | yes | 4–6 digit code the user typed |

## Sample request

```curl
curl \
  -X POST \
  "https://developer.bdapps.com/subscription/otp/verify" \
  -H "Content-Type: application/json" \
  -d '{
    "applicationId": "APP_137539",
    "password": "<BDAPPS_PASSWORD>",
    "referenceNo": "REF-12345",
    "otp": "1234"
  }'
```

## Sample success response (HTTP 200)

```json
{
  "subscriptionStatus": "REGISTERED",
  "statusCode": "S1000",
  "statusDetail": "Success",
  "subscriberId": "tel:8801812345678",
  "version": "1.0"
}
```

## Sample error response

```json
{
  "statusCode": "E1325",
  "statusDetail": "Request is Invalid.",
  "subscriptionStatus": "UNREGISTERED"
}
```

## Sample `TEMPORARY BLOCKED` response

When the gateway accepts the OTP but the operator has blocked
the line:

```json
{
  "subscriptionStatus": "TEMPORARY BLOCKED",
  "statusCode": "S1000",
  "statusDetail": "Success",
  "subscriberId": "tel:8801812345678",
  "version": "1.0"
}
```

We treat this like `UNREGISTERED` — row + user land at
`unregistered`, no token is issued.

## Status code matrix

| statusCode | meaning |
|---|---|
| `S1000` | OTP valid; check `subscriptionStatus` — `REGISTERED`, `TEMPORARY BLOCKED`, etc. |
| `E1325` | OTP wrong or expired |

## Implementation

- Service: `app/Services/BdApps/BdAppsService.php` (`verifyOtp()`).
- Caller: `app/Services/BdApps/SubscriptionService.php` (`verifyOtp()`).
- On `S1000` with `REGISTERED` → user → `registered`,
  `subscribed_at` and `phone_verified_at` stamped.
- On `S1000` with PENDING-family → user → `pending`,
  `phone_verified_at` stamped.
- On `S1000` with `UNREGISTERED` / `EXPIRED` /
  `TEMPORARY BLOCKED` → user → `unregistered`,
  `phone_verified_at` stamped but no token issued.