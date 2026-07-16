# Webhooks

## POST /api/webhooks/bdapps/notify

Receives subscription status notifications from Robi BDApps. The
gateway pushes events when the user subscribes, unsubscribes (e.g.
via STOP reply), or when a daily charge fails.

Authentication:

- `applicationId` in body must equal `config('bdapps.application_id')`.
- If `config('bdapps.notify_secret')` is non-empty, the call must
  present the same value in `X-Bdapps-Secret` header (or
  `notify_secret` body field). Compared with `hash_equals`.

Body fields:

| field | type | required | description |
|---|---|---|---|
| applicationId | string | yes | Must match our app id |
| subscriberId | string | yes | `tel:880XXXXXXXXXX` form |
| status | string | yes | `REGISTERED` \| `UNREGISTERED` \| `EXPIRED` |
| frequency | string | no | E.g. `daily`, `monthly` |
| timeStamp | string | no | Gateway timestamp |

On success we mirror `status` onto the user (`subscribed` or
`unsubscribed`) and update the latest `bdapps_subscriptions` row.

###### POST /api/webhooks/bdapps/notify

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

Sample success response:

```json
{
  "statusCode": "S1000",
  "statusDetail": "Request was successfully processed"
}
```

If the applicationId doesn't match, or the notify_secret is wrong:

```json
{
  "statusCode": "E1001",
  "statusDetail": "Unauthorized."
}
```
