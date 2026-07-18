# Webhooks

## POST /api/webhooks/bdapps/notify

Receives subscription status notifications from Robi BDApps. The
gateway pushes events when the user subscribes, unsubscribes (e.g.
via STOP reply), or when a daily charge fails.

Authentication:

The gateway posts to a configured URL and trusts the receiver — the
quiz_app PHP reference (`projects/nadb/quiz_app/bdapps_api_php/
subscription_listener.php`) does no auth at all. We mirror that
posture: the only guard is an `applicationId` sanity check so a
misrouted POST from a different app doesn't get applied to our
users. The webhook endpoint is intended to live behind a firewall
or a network-level ACL rather than bearer / shared-secret auth.

Body fields (matches the PHP listener's parse list):

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

If the applicationId doesn't match:

```json
{
  "statusCode": "E1001",
  "statusDetail": "Unauthorized."
}
```

---

## POST /api/webhooks/bdapps/sms

Receives Mobile-Originated SMS that an end user sent to the keyword
`99898` (short code `21213`). Log-only — no DB write, no automatic
reply, no phone → user lookup. The full payload is written to the
`bdapps` log channel for forensics and the gateway is acknowledged
with `S1000` so it stops retrying.

Body fields (per BDApps SMS Receive docs):

| field | type | required | description |
|---|---|---|---|
| version | string | yes | API version, e.g. `1.0` |
| applicationId | string | yes | Must match `config('bdapps.application_id')` |
| sourceAddress | string | yes | Sender `tel:880XXXXXXXXXX` |
| message | string | yes | User's text |
| requestId | string | yes | Gateway request id |
| encoding | string | yes | `0` \| `240` \| `245` |

###### POST /api/webhooks/bdapps/sms

```curl
curl \
  -X POST \
  "$APP_URL/api/webhooks/bdapps/sms" \
  -H "Content-Type: application/json" \
  -d '{
    "version": "1.0",
    "applicationId": "APP_137539",
    "sourceAddress": "tel:8801812345678",
    "message": "HELP",
    "requestId": "REQ-98765",
    "encoding": "0"
  }'
```

Sample success response:

```json
{
  "statusCode": "S1000",
  "statusDetail": "Request was successfully processed"
}
```

If we ever want to react to MO keywords (STOP, BAL, HELP), this is
the controller to extend. Keep the body thin.


## Logging

Every inbound webhook is logged to the dedicated `bdapps` channel
(`storage/logs/bdapps-YYYY-MM-DD.log`, JSON formatter):

- `bdapps.notify_received` — first entry, includes the full body
  + headers + IP, so even auth failures leave a forensic trail.
  This is the "route called" log — it fires unconditionally on every
  invocation, mirroring the PHP listener's FSubNoti.txt write.
- `bdapps.notify_app_id_mismatch` — auth rejection (also returns 401
  to the gateway)
- `bdapps.notify_missing_fields` / `bdapps.notify_invalid_subscriber_id`
  — bad payload rejections
- `bdapps.notify_unknown_phone` — phone not in our DB (acknowledge S1000
  anyway so BDApps doesn't retry forever)
- `bdapps.notify_applied` — successful state change, with user_id, phone,
  status, frequency, and the gateway timestamp
- `bdapps.notify_misconfigured` — `bdapps.application_id` is empty
  in our config (500 to the gateway)

The same `bdapps` channel also carries:

- **Outbound request/response entries** written by `BdAppsService`
  (`bdapps.otp.request`, `bdapps.otp.verify`,
  `bdapps.subscription.send`, `bdapps.subscription.getStatus`) with
  the password redacted.
- **Orchestrator-level failure entries** written by `SubscriptionService`
  when the gateway rejects a call or transport fails:
  - `bdapps.subscribe_failed_on_register` — `/otp/request` rejected
    on registration (fields: `phone`, `status_code`, `status_detail`,
    or `transport_error`).
  - `bdapps.otp_request_failed` — `/otp/request` rejected mid-session
    (e.g. when re-issuing a reference for verify).
  - `bdapps.verify_failed` — `/otp/verify` rejected (wrong/expired
    OTP). Fields: `user_id`, `phone`, `reference_no`, `status_code`,
    `status_detail`.
  - `bdapps.unsubscribe_failed` — `/subscription/send` rejected on
    cancel. Fields: `user_id`, `phone`, `status_code`,
    `status_detail`, or `transport_error`.

A single `tail -f storage/logs/bdapps-$(date +%F).log` shows the
full conversation between our backend and Robi BDApps.
