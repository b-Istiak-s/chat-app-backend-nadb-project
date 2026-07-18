# BDApps — Webhook payload (notify)

Source: `~/projects/nadb/quiz_app/bdapps_api_php/subscription_listener.php`

BDApps pushes subscription state changes here. We expose
`POST /api/webhooks/bdapps/notify`.

Authentication:

The gateway posts to a configured URL and trusts the receiver — the
PHP listener at the source above does no auth at all. We mirror
that posture: the only guard is an `applicationId` sanity check
(`config('bdapps.application_id')`). The endpoint is intended to
live behind a firewall or a network-level ACL rather than bearer /
shared-secret auth.

## Payload

| field | type | required | description |
|---|---|---|---|
| applicationId | string | yes | App id issued by BDApps |
| subscriberId | string | yes | `tel:880XXXXXXXXXX` |
| status | string | yes | `REGISTERED` \| `UNREGISTERED` \| `EXPIRED` |
| frequency | string | no | E.g. `daily` |
| timeStamp | string | no | Gateway-side timestamp |

These five are the fields the PHP listener parses off the raw body.
The official BDApps spec lists a wider "required" set (`version`,
`password` in addition), but in practice the gateway does not send
those to webhook subscribers — the PHP listener ignores them and so
do we. Any extra fields the gateway may add are still captured in
`bdapps.notify_received.payload` for forensic visibility.

## Sample request

```curl
curl \
  -X POST \
  "$APP_URL/api/webhooks/bdapps/notify" \
  -H "Content-Type: application/json" \
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
| 401 | `E1001` | applicationId mismatch |
| 400 | `E1002` | missing subscriberId or status |
| 400 | `E1003` | invalid subscriberId format |
| 500 | `E1000` | webhook not configured (missing env vars) |

## Implementation

- Controller: `app/Http/Controllers/Webhook/BdAppsNotifyController.php`.
- Service: `app/Services/BdApps/SubscriptionService.php` (`applyNotifyStatus()`).
- Unknown phones are acknowledged with `S1000` (don't retry forever).