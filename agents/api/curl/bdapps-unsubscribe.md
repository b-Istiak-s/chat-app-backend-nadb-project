# BDApps — POST /subscription/send (action=0)

Source: `~/projects/nadb/quiz_app/bdapps_api_php/unsubscribe.php`

Cancel the user's subscription. Uses the same `/subscription/send`
endpoint as subscribe, but with `action: "0"` instead of `action: "1"`.

| field | type | required | description |
|---|---|---|---|
| version | string | yes | API version, e.g. `1.0` |
| applicationId | string | yes | App id |
| password | string | yes | Encoded app hash |
| subscriberId | string | yes | `tel:880XXXXXXXXXX` |
| action | string | yes | `"0"` to unsubscribe, `"1"` to subscribe |

## Sample request

```curl
curl \
  -X POST \
  "https://developer.bdapps.com/subscription/send" \
  -H "Content-Type: application/json" \
  -d '{
    "version": "1.0",
    "applicationId": "APP_137539",
    "password": "<BDAPPS_PASSWORD>",
    "subscriberId": "tel:8801812345678",
    "action": "0"
  }'
```

## Sample success response (HTTP 200)

```json
{
  "subscriptionStatus": "UNREGISTERED",
  "statusCode": "S1000",
  "statusDetail": "Success",
  "version": "1.0"
}
```

## Sample error response

```json
{
  "statusCode": "E1325",
  "statusDetail": "Address should a string or a array of strings",
  "subscriptionStatus": "UNKNOWN"
}
```

## Status code matrix

| statusCode | meaning |
|---|---|
| `S1000` | Success — `subscriptionStatus: UNREGISTERED` expected |
| `E1325` | Address format invalid |

## Implementation

- Service: `app/Services/BdApps/BdAppsService.php` (`unsubscribe()`).
- Caller: `app/Services/BdApps/SubscriptionService.php` (`cancelSubscription()`).
- Idempotent at the gateway: a repeat call returns the same
  `UNREGISTERED` status.