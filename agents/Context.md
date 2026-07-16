# Project Context — ChatApp Backend

## Overview

Minimal Laravel 13 API for a Robi/BDApps-subscribed chat app used by a
single user type (subscribers). Two screens after login: AI chat
(streaming via SSE) and settings (logout + unsubscribe).

The BDApps integration shape was lifted from
`~/projects/nadb/quiz_app/bdapps_api_php/`. The Flutter app mirrors
`~/projects/cuno/cuno-therapy/app/` with the auth flow stripped down
to phone-only subscription login.

## What Has Been Built

### Foundation
- Laravel 13, PHP 8.3+, MySQL/MariaDB.
- Laravel Sanctum for API token authentication.
- Phone-based authentication — no email, no password, no role middleware.
- Standardized JSON envelope via `App\Traits\ApiResponses\JsonResponseTrait`.

### Subscription (Robi BDApps)
- `App\Services\BdApps\BdAppsService` — CURL wrapper for the BDApps
  gateway (mirrors quiz_app PHP endpoint shapes verbatim).
- `App\Services\BdApps\SubscriptionService` — orchestrates the
  OTP-based subscribe / verify / unsubscribe lifecycle around a User.
- `App\Models\BdappsSubscription` — local mirror of the gateway state.
- `App\Repositories\BdappsSubscriptionRepository` — data access.
- `App\Http\Controllers\Webhook\BdAppsNotifyController` — receives
  `POST /api/webhooks/bdapps/notify`, validates shared notify_secret
  via constant-time compare, applies status changes to user + subscription.

### Endpoints

**Auth (public):**
- `POST /api/auth/start`     — find-or-create user, send BDApps OTP, return `{token?, requiresOtp, referenceNo}`
- `POST /api/auth/verify`    — verify OTP, mark subscribed, return `{token}`

**Auth (protected, `auth:sanctum`):**
- `GET  /api/auth/me`        — phone + subscription status
- `POST /api/auth/logout`    — revoke token
- `POST /api/auth/unsubscribe` — cancel BDApps subscription

**Chat (protected, `auth:sanctum`):**
- `POST /api/chat/messages`  — SSE stream of AI reply (`Content-Type: text/event-stream`)
- `GET  /api/chat/messages`  — paginated JSON history

**Webhook (public, shared notify_secret):**
- `POST /api/webhooks/bdapps/notify`

### AI Chat
- One conversation per user (unique `user_id` on `chat_conversations`).
- OpenRouter integration via OpenAI-compatible client.
- SSE streaming for real-time token delivery.
- Full message history stored in `chat_messages` (`role` + `content`).

### Architecture

```
Route → Middleware → FormRequest → Controller → Service → Repository → Model
```

- Controllers: `Api\Auth\AuthController`, `Api\Chat\ChatController`,
  `Webhook\BdAppsNotifyController`.
- Services: `BdApps\BdAppsService`, `BdApps\SubscriptionService`,
  `OpenRouterService`, `Chat\ChatService`.
- Repositories: `BdappsSubscriptionRepository`.
- Models: `User`, `BdappsSubscription`, `ChatConversation`, `ChatMessage`.

### Phone normalisation

BDApps requires `tel:<country><10digits>` subscriber ids. Bangladesh
local numbers `01[3-9][0-9]{8}` are normalised by
`BdAppsService::formatSubscriberId()`:

| Input             | Output                    |
|-------------------|---------------------------|
| `01812345678`     | `tel:8801812345678`       |
| `8801812345678`   | `tel:8801812345678`       |
| `881812345678`    | `tel:8801812345678`       |
| `tel:8801812345678` | `tel:8801812345678`     |

## Environment

Required `.env` keys:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=chat_app
DB_USERNAME=...
DB_PASSWORD=...

BDAPPS_BASE_URL=https://developer.bdapps.com
BDAPPS_APPLICATION_ID=APP_137539
BDAPPS_PASSWORD=<encoded hash from BDApps dashboard>
BDAPPS_APPLICATION_HASH=ChatApp
BDAPPS_NOTIFY_SECRET=<shared secret used by /api/webhooks/bdapps/notify>
BDAPPS_TIMEOUT=30
BDAPPS_VERIFY_SSL=true
BDAPPS_COUNTRY_CODE=880
BDAPPS_SUCCESS_STATUS_CODE=S1000

OPENROUTER_API_KEY=sk-or-...
OPENROUTER_BASE_URL=https://openrouter.ai/api/v1
OPENROUTER_MODEL=openai/gpt-4o-mini
OPENROUTER_TIMEOUT=60
```

## Outstanding

- Crisis detection (deliberately skipped — only AI chat, no therapist).
- Push notifications.
- Cron-driven reconciliation job (sync local state with `getStatus`).
