# Authentication API

## POST /api/auth/start

Find-or-create user by phone.

- If the user is `subscribed` OR has a `pending` subscription row
  (i.e. the gateway is still finalising their activation), a Sanctum
  token is returned **immediately** and a courtesy SMS is sent via
  BDApps `/sms/send` so the user has a record of the login. No OTP,
  no password — if they're trusted, they're in.
- Otherwise the standard BDApps OTP flow runs and the response is
  `{token: null, requires_otp: true, reference_no: ...}`.

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

Sample success response — already-subscribed user (no OTP):

```json
{
  "success": true,
  "message": "Logged in.",
  "data": {
    "token": "1|abcdef...",
    "requires_otp": false,
    "reference_no": null,
    "subscription_status": "subscribed"
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

Verify the OTP BDApps sent. On success marks the user subscribed and
returns a Sanctum token.

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
    "subscription_status": "REGISTERED"
  }
}
```

---

## GET /api/auth/me

Return the authenticated user's phone + subscription state. Requires
`Authorization: Bearer <token>`.

###### GET /api/auth/me

```curl
curl \
  -X GET \
  "$APP_URL/api/auth/me" \
  -H "Authorization: Bearer $TOKEN"
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

Cancel the user's BDApps subscription. Best-effort: local state is
flipped to `unsubscribed` regardless of gateway outcome.

###### POST /api/auth/unsubscribe

```curl
curl \
  -X POST \
  "$APP_URL/api/auth/unsubscribe" \
  -H "Authorization: Bearer $TOKEN"
```
