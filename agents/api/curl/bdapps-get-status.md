# BDApps — POST /subscription/getStatus

Source: `~/projects/nadb/quiz_app/bdapps_api_php/check_subscription.php`

Query the current subscription status of a subscriber. Used to
reconcile local state when the user has unsubscribed externally
(e.g. via STOP reply).

| field | type | required | description |
|---|---|---|---|
| applicationId | string | yes | App id |
| password | string | yes | Encoded app hash |
| subscriberId | string | yes | `tel:880XXXXXXXXXX` |

## Sample request

```curl
curl \
  -X POST \
  "https://developer.bdapps.com/subscription/getStatus" \
  -H "Content-Type: application/json" \
  -d '{
    "applicationId": "APP_137539",
    "password": "<BDAPPS_PASSWORD>",
    "subscriberId": "tel:8801812345678"
  }'
```

## Sample success response (HTTP 200)

```json
{
  "subscriptionStatus": "REGISTERED",
  "statusCode": "S1000",
  "statusDetail": "Success",
  "version": "1.0"
}
```

For an unregistered subscriber:

```json
{
  "subscriptionStatus": "UNREGISTERED",
  "statusCode": "S1000",
  "statusDetail": "Success",
  "version": "1.0"
}
```

## Status code matrix

| statusCode | meaning |
|---|---|
| `S1000` | Transport OK — check `subscriptionStatus` for the answer |

Note: per `getStatus` contract, an `UNREGISTERED` response still
returns `S1000`. We treat `subscriptionStatus: REGISTERED` as the
only positive result; anything else is "not subscribed".

## Implementation

- Service: `app/Services/BdApps/BdAppsService.php` (`getStatus()`).
- (Currently unused in the happy path — `/start` short-circuits when
  the user is already subscribed. Kept for the future cron-driven
  reconciliation job.)