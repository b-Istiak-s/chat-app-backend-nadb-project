# BDApps — Webhook payload (notify)

Source: `~/projects/nadb/quiz_app/bdapps_api_php/subscription_listener.php`

BDApps pushes subscription state changes here. We expose
`POST /api/webhooks/bdapps/notify`.

Authentication:

- `applicationId` in body must equal `config('bdapps.application_id')`.
- If `config('bdapps.notify_secret')` is non-empty, the call must
  present the same value in `X-Bdapps-Secret` header.

## Payload

| field | type | required | description |
|---|---|---|---|
| applicationId | string | yes | App id issued by BDApps |
| subscriberId | string | yes | `tel:880XXXXXXXXXX` |
| status | string | yes | `REGISTERED` \| `UNREGISTERED` \| `EXPIRED` |
| frequency | string | no | E.g. `daily` |
| timeStamp | string | no | Gateway-side timestamp |

## Sample request

```curl
curl \
  -X POST \
  "$APP_URL/api/webhooks/bdapps/notify" \
  -H "Content-Type: application/json" \
  -H "X-Bdapps-Secret: $BDAPPS_NOTIFY_SECRET" \
  -d '{
    "applicationId": "APP_137539",
    "subscriberId": "tel:8801812345678",
    "status": "REGISTERED",
    "frequency": "daily",
    "timeStamp": "2026-07-16T12:00:00+06:00"
  }'
```

## Sample success response (HTTP 200)

```json
{
  "statusCode": "S1000",
  "statusDetail": "Request was successfully processed"
}
```

## Failure responses

| http | statusCode | reason |
|---|---|---|
| 401 | `E1001` | applicationId mismatch OR notify_secret mismatch |
| 400 | `E1002` | missing subscriberId or status |
| 400 | `E1003` | invalid subscriberId format |
| 500 | `E1000` | webhook not configured (missing env vars) |

## Implementation

- Controller: `app/Http/Controllers/Webhook/BdAppsNotifyController.php`.
- Service: `app/Services/BdApps/SubscriptionService.php` (`applyNotifyStatus()`).
- Constant-time secret compare via `hash_equals`.
- Unknown phones are acknowledged with `S1000` (don't retry forever).