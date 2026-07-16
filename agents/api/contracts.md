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
| 401 | Missing or invalid Bearer token; bad webhook secret |
| 404 | User not found (e.g. `/verify` for an unknown phone) |
| 502 | Gateway failure (`bdapps.otp_request_failed`, etc.) |
| 500 | Misconfiguration on our side |

## Phone number format

User input accepts `01[3-9][0-9]{8}` (11 chars, local Bangladesh). The
backend normalises to `tel:880<10digits>` (14 chars after the `tel:`
prefix) before calling BDApps. See
[`app/Services/BdApps/BdAppsService.php`](../../app/Services/BdApps/BdAppsService.php).
