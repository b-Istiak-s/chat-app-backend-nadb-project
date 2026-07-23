# Authentication API

## POST /api/auth/start

Find-or-create user by phone.

- If the user is in a token-bearing state (`pending` or
  `registered`), a Sanctum token is returned **immediately** and a
  courtesy SMS is sent via BDApps `/sms/send` so the user has a
  record of the login. No OTP, no password — if they're trusted,
  they're in.
- Otherwise the standard BDApps OTP flow runs and the response is
  `{token: null, requires_otp: true, reference_no: ...}`.

Subscription states (mirror of `users.subscription_status`):

| State          | Token? | Description                                                       |
|----------------|--------|-------------------------------------------------------------------|
| `unverified`   | No     | OTP not yet entered (one-shot for first registration).            |
| `pending`      | Yes    | OTP verified, BDApps mid-charge.                                 |
| `registered`   | Yes    | BDApps confirmed `REGISTERED`. Full feature access.              |
| `unregistered` | No     | Terminal: user cancelled, or gateway replied `UNREGISTERED` /    |
|                |        | `EXPIRED` / `TEMPORARY BLOCKED`.                                 |

| field | type | required | description |
|---|---|---|---|
| phone | string | yes | Bangladesh mobile number, regex `^01[3-9][0-9]{8}$` |

###### POST /api/auth/start

```curl
curl \
  -X POST \
  "$APP_URL/api/auth/start" \
  -H "Content-Type: application/json" \
  -d '{
    "phone": "01812345678"
  }'
```

Sample success response — already-registered user (no OTP):

```json
{
  "success": true,
  "message": "Logged in.",
  "data": {
    "token": "1|abcdef...",
    "requires_otp": false,
    "reference_no": null,
    "subscription_status": "registered",
    "is_verified": true
  }
}
```

Sample success response — new user (OTP required):

```json
{
  "success": true,
  "message": "OTP requested.",
  "data": {
    "token": null,
    "requires_otp": true,
    "reference_no": "REF-12345"
  }
}
```

---

## POST /api/auth/verify

Verify the OTP BDApps sent.

Soft activation: any non-terminal reply (REGISTERED, INITIAL
CHARGING PENDING, CHARGE_PENDING, PENDING) flips the user to
`pending` or `registered` and issues a Sanctum token. Terminal
replies (`UNREGISTERED` / `EXPIRED` / `TEMPORARY BLOCKED`) move
the user to `unregistered` and **no token is issued**.

| field | type | required | description |
|---|---|---|---|
| phone | string | yes | Same phone used in `/start` |
| otp | string | yes | 4–6 digit code, regex `^[0-9]{4,6}$` |

###### POST /api/auth/verify

```curl
curl \
  -X POST \
  "$APP_URL/api/auth/verify" \
  -H "Content-Type: application/json" \
  -d '{
    "phone": "01812345678",
    "otp": "1234"
  }'
```

Sample success response:

```json
{
  "success": true,
  "message": "Phone verified successfully.",
  "data": {
    "token": "1|abcdef...",
    "subscription_status": "pending",
    "is_verified": true
  }
}
```

---

## GET /api/auth/me

Return the authenticated user's phone + subscription state.
Requires `Authorization: Bearer <token>`.

This endpoint is also the **forced-logout gate**. If the user's
subscription is no longer token-bearing (e.g. background
reconciliation flipped the row to `unregistered`), every Sanctum
token for that user is deleted and a 401 is returned:

```json
{
  "success": false,
  "message": "Your subscription is no longer active. Please sign in again.",
  "data": [],
  "error_code": "forced_logout"
}
```

Clients should match `error_code` (`forced_logout` for a
subscription-flip; `subscription_required` for an `unverified`
edge case), clear the locally stored bearer, and bounce to
`/start`.

###### GET /api/auth/me

```curl
curl \
  -X GET \
  "$APP_URL/api/auth/me" \
  -H "Authorization: Bearer $TOKEN"
```

Sample success response:

```json
{
  "success": true,
  "message": "Response Successful",
  "data": {
    "id": 42,
    "phone": "01812345678",
    "subscription_status": "pending",
    "is_verified": true,
    "subscribed_at": null
  }
}
```

---

## POST /api/auth/logout

Revoke the current Sanctum token. Requires authentication.

###### POST /api/auth/logout

```curl
curl \
  -X POST \
  "$APP_URL/api/auth/logout" \
  -H "Authorization: Bearer $TOKEN"
```

---

## POST /api/auth/unsubscribe

Cancel the user's BDApps subscription. Best-effort: local state
is flipped to `unregistered` regardless of gateway outcome.

###### POST /api/auth/unsubscribe

```curl
curl \
  -X POST \
  "$APP_URL/api/auth/unsubscribe" \
  -H "Authorization: Bearer $TOKEN"
```
