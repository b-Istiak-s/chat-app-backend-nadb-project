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
- Called by the `bdapps:poll-pending` artisan command
  (`app/Console/Commands/PollPendingBdappsSubscriptionsCommand.php`),
  scheduled every 5 minutes via `routes/console.php`. Only touches
  rows with local `status='pending'` and `started_at <= now() - 5min`;
  registered / unregistered rows are skipped.
- When the gateway returns a `subscriberId` in its body (the base64
  wire form), it is persisted on `bdapps_subscriptions
  .gateway_subscriber_id` and used for future `/subscription/send`
  calls (e.g. unsubscribe).