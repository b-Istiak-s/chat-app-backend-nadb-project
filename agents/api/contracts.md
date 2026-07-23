# API Contracts

This file tracks the wire-level contracts every endpoint exposes.

## Common envelope

```json
{ "success": true|false, "message": "Human readable", "data": ..., "errors": {...}? }
```

- `success` — boolean.
- `message` — short human-readable description.
- `data` — endpoint-specific payload. May be `null` on errors.
- `errors` — present on validation failures; key = field name, value =
  array of messages.

## SSE event envelope

For streaming endpoints (`/api/chat/messages`):

```
Content-Type: text/event-stream
Cache-Control: no-cache, no-transform
X-Accel-Buffering: no
Connection: keep-alive
```

Each event is one line `data: <json>\n\n` where `<json>` matches one
of:

| shape | meaning |
|---|---|
| `{"chunk":"<text>"}` | one OpenRouter delta chunk |
| `{"done":true,"message_id":<id>}` | stream complete, assistant message persisted |
| `{"error":"<message>"}` | stream errored |

## Error codes

The backend maps gateway / app errors to standard HTTP codes:

| code | meaning |
|---|---|
| 422 | Validation failure (`errors` populated) |
| 401 | Missing or invalid Bearer token; bad webhook secret; **`/auth/me` forced-logout gate fired** (`error_code` distinguishes — see below) |
| 404 | User not found (e.g. `/verify` for an unknown phone) |
| 502 | Gateway failure (`bdapps.otp_request_failed`, etc.) |
| 500 | Misconfiguration on our side |

### `error_code` discriminator

401 responses from `/api/auth/me` carry an `error_code` field so
clients can branch on the cause without parsing the message:

| `error_code`            | When                                                             |
|-------------------------|------------------------------------------------------------------|
| `forced_logout`         | The server-side gate detected a non-token-bearing row (`unregistered`) and wiped the user's tokens. |
| `subscription_required` | The user was `unverified` / had no row — no token should exist.   |
| *(missing)*             | Generic 401 — token expired / revoked / malformed.              |

## Phone number format

User input accepts `01[3-9][0-9]{8}` (11 chars, local Bangladesh). The
backend normalises to `tel:880<10digits>` (14 chars after the `tel:`
prefix) before calling BDApps. See
[`app/Services/BdApps/BdAppsService.php`](../../app/Services/BdApps/BdAppsService.php).
