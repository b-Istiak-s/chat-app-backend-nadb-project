## POST https://developer.bdapps.com/sms/send

Send a Mobile-Terminated SMS to one or more destination addresses via the
Robi BDApps gateway. Used by the backend for:
- the courtesy "you just signed in" notification (driven by
  `SubscriptionService::notifyLogin`)
- milestone pings every 5 AI chats (driven by `SmsService`)

This is the **outbound** side of the gateway integration — there's
no public backend route that proxies it. The `BdAppsService::sendSms`
helper in `app/Services/BdApps/BdAppsService.php` builds the request
shape below.

| field | type | required | description |
|---|---|---|---|
| version | string | yes | API version, e.g. `1.0` |
| applicationId | string | yes | Must equal `config('bdapps.application_id')` |
| password | string | yes | Encoded hash from the BDApps dashboard |
| message | string | yes | Body of the SMS — broken up by the gateway if too long |
| destinationAddresses | string[] | yes | List of `tel:880XXXXXXXXXX` addresses; we always pass one |
| sourceAddress | string | no | Sender `tel:880XXXXXXXXXX` (uses SLA alias if omitted) |
| deliveryStatusRequest | string | no | `1` to require a DSR, `0` otherwise; we default `0` |
| encoding | string | no | `0` text (default), `240` flash, `245` binary |
| binaryHeader | string | no | Hex header for advanced / binary messages |

###### POST /sms/send

```curl
curl \
  -X POST \
  "https://developer.bdapps.com/sms/send" \
  -H "Content-Type: application/json;charset=utf-8" \
  -d '{
    "version": "1.0",
    "applicationId": "APP_137539",
    "password": "<BDAPPS_PASSWORD>",
    "message": "You have sent 5 chats today. Keep going!",
    "destinationAddresses": ["tel:8801812345678"]
  }'
```

Sample success response (HTTP 200):

```json
{
  "version": "1.0",
  "requestId": "req-abc-123",
  "destinationResponses": [
    {
      "address": "tel:8801812345678",
      "timeStamp": "2026-07-17T12:00:00+06:00",
      "messageId": "msg-xyz-789",
      "statusCode": "S1000",
      "statusDetail": "Process completed successfully"
    }
  ],
  "statusCode": "S1000",
  "statusDetail": "Process completed successfully"
}
```

Common gateway error codes we handle (see
`config/bdapps.php` for the full list):

- `E1303` — IP not provisioned for this app
- `E1308` — Insufficient balance
- `E1311` — MT SMS not enabled
- `E1312` — Invalid payload
- `E1313` — Auth failed (bad `applicationId` / `password`)
- `E1325` — Bad `tel:` format
- `E1334` — Message too long
- `E1343` — MSISDN not whitelisted

Failed sends are caught in `SmsService` / `SubscriptionService` and
logged to the `bdapps` channel; an SMS failure never breaks the
calling API request — a missed milestone or login ping is logged
but doesn't surface to the user.

## Logging

Every `sendSms` call emits one structured `bdapps.sms.send` entry to
`storage/logs/bdapps-YYYY-MM-DD.log` with:

- `request` — the outbound payload (password masked as `****`)
- `response` — gateway JSON
- `http_status` — underlying HTTP status
