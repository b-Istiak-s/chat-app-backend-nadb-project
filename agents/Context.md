# Project Context — Chat App Backend

## Overview

Minimal Laravel 13 API for a Robi/BDApps-subscribed chat app used
by a single user type (subscribers). Two screens after login: AI
chat (streaming via SSE) and settings (logout + unsubscribe).

The BDApps integration shape was lifted from
`~/projects/nadb/quiz_app/bdapps_api_php/`. The Flutter app mirrors
`~/projects/cuno/cuno-therapy/app/` with the auth flow stripped
down to phone-only subscription login.

## What Has Been Built

### Foundation
- Laravel 13, PHP 8.3+, MySQL/MariaDB.
- Laravel Sanctum for API token authentication.
- Phone-based authentication — no email, no password, no role
  middleware.
- Standardized JSON envelope via
  `App\Traits\ApiResponses\JsonResponseTrait`.

### Subscription (Robi BDApps)

Four-state model (mirror of the BDApps gateway state):

| State          | User has token?     | Notes                                 |
|----------------|---------------------|---------------------------------------|
| `unverified`   | No                  | One-shot for first registration;      |
|                |                     | stamped `phone_verified_at` once OTP  |
|                |                     | has been entered successfully. Never  |
|                |                     | re-enters once `phone_verified_at`    |
|                |                     | is set.                               |
| `pending`      | Yes                 | OTP verified, BDApps mid-charge.      |
|                |                     | UI locked to `payment-not-confirmed`  |
|                |                     | until the gateway confirms `REGISTERED`. |
| `registered`   | Yes                 | BDApps confirmed `REGISTERED`. Full   |
|                |                     | feature access (chat, APK download).  |
| `unregistered` | No                  | Terminal. User cancelled, or gateway  |
|                |                     | replied `UNREGISTERED` / `EXPIRED` / |
|                |                     | `TEMPORARY BLOCKED`.                 |

- `App\Services\BdApps\BdAppsService` — HTTP wrapper for the
  BDApps gateway. `TERMINAL_FAILURE_STATUSES` covers
  `UNREGISTERED`, `EXPIRED`, `TEMPORARY BLOCKED`.
- `App\Services\BdApps\SubscriptionService` — orchestrates the
  OTP-based subscribe / verify / unsubscribe lifecycle around a
  User. The state machine docblock at the top of the class
  describes the full flow.
- `App\Models\BdappsSubscription` — local mirror of the gateway
  state. `STATUS_*` constants + per-state scopes
  (`scopeLive`, `scopePending`, `scopeRegistered`,
  `scopeUnregistered`).
- `App\Models\User::isAwaitingOtp()` / `isSubscriptionPending()`
  / `isRegistered()` / `isUnregistered()` —
  `isTokenBearing()` covers `pending || registered`.
- `App\Repositories\BdappsSubscriptionRepository` — data
  access. Notable: `unregisteredForUser()` for the
  `/auth/me` forced-logout gate.
- `App\Http\Controllers\Webhook\BdAppsNotifyController` —
  receives `POST /api/webhooks/bdapps/notify`. Aligns with the
  quiz_app PHP listener's 5-field set (`timeStamp`, `status`,
  `applicationId`, `subscriberId`, `frequency`); the only auth
  is an applicationId sanity check. Applies status changes via
  `SubscriptionService::applyNotifyStatus()`.

### Endpoints

**Auth (public):**
- `POST /api/auth/start`     — find-or-create user, send BDApps
  OTP, return `{token?, requiresOtp, referenceNo}`. A token is
  issued immediately for users in a token-bearing state
  (`pending` or `registered`).
- `POST /api/auth/verify`    — verify OTP. Soft activation: any
  non-terminal reply (REGISTERED, PENDING-family) flips the user
  to `pending` or `registered` and issues a Sanctum token.
  Terminal replies (`UNREGISTERED`/`EXPIRED`/`TEMPORARY
  BLOCKED`) move the user to `unregistered` and **no token is
  issued**.

**Auth (protected, `auth:sanctum`):**
- `GET  /api/auth/me`        — phone + subscription status.
  Returns `{id, phone, subscription_status, is_verified,
  subscribed_at}`. **Forced-logout gate**: if the user's
  subscription is no longer token-bearing (e.g. background
  reconciliation flipped them to `unregistered`), all Sanctum
  tokens are deleted and a 401 is returned with
  `error_code: forced_logout` (or `subscription_required`).
- `POST /api/auth/logout`    — revoke token
- `POST /api/auth/unsubscribe` — cancel BDApps subscription;
  user + row move to `unregistered`

**Chat (protected, `auth:sanctum`):**
- `POST /api/chat/messages`  — SSE stream of AI reply
  (`Content-Type: text/event-stream`)
- `GET  /api/chat/messages`  — paginated JSON history

**Webhook (public, applicationId sanity check):**
- `POST /api/webhooks/bdapps/notify`

**Web dashboard (cookie session via `web` guard — separate from
Sanctum):**
- `GET  /`                       — landing page (CTAs to
  `/login`)
- `GET  /login`                  — phone form (or OTP step when
  pending)
- `POST /login/start`            — kick BDApps OTP. Trust path:
  `pending` or `registered` → sign in directly.
- `POST /login/verify`           — verify OTP, sign web session
  in. Soft activation: any non-terminal reply.
- `POST /logout`                 — sign web session out
- `GET  /dashboard`              — subscription status +
  controls. Renders five states: `unregistered`, `unverified`
  (awaiting OTP), `pending` (Payment not confirmed,
  auto-refreshing while the cron + 10s job reconcile),
  `registered`. (auth)
- `POST /dashboard/subscribe`    — kick OTP, mark awaiting-Otp
  in session (auth)
- `POST /dashboard/verify`       — verify OTP (auth)
- `POST /dashboard/refresh`      — poll gateway now and apply
  the result (auth) — the "Refresh status now" button on the
  payment-not-confirmed view
- `POST /dashboard/unsubscribe`  — cancel BDApps subscription
  (auth)
- `GET  /downloads/app.apk`      — gated APK download, 403 unless
  `registered` (auth)

### AI Chat
- One conversation per user (unique `user_id` on
  `chat_conversations`).
- OpenRouter integration via OpenAI-compatible client.
- SSE streaming for real-time token delivery.
- Full message history stored in `chat_messages`
  (`role` + `content`).

### Architecture

```
Route → Middleware → FormRequest → Controller → Service → Repository → Model
```

- Controllers: `Api\Auth\AuthController`, `Api\Chat\ChatController`,
  `Webhook\BdAppsNotifyController`, `Web\WebAuthController`,
  `Web\DashboardController`.
- Services: `BdApps\BdAppsService`, `BdApps\SubscriptionService`,
  `OpenRouterService`, `Chat\ChatService`.
- Repositories: `BdappsSubscriptionRepository`.
- Models: `User`, `BdappsSubscription`, `ChatConversation`,
  `ChatMessage`.

### Phone normalisation

BDApps requires `tel:<country><10digits>` subscriber ids.
Bangladesh local numbers `01[3-9][0-9]{8}` are normalised by
`BdAppsService::formatSubscriberId()`:

| Input               | Output                  |
|---------------------|-------------------------|
| `01812345678`       | `tel:8801812345678`     |
| `8801812345678`     | `tel:8801812345678`     |
| `881812345678`      | `tel:8801812345678`     |
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
BDAPPS_APPLICATION_HASH="Chat App"
BDAPPS_NOTIFY_SECRET=<shared secret used by /api/webhooks/bdapps/notify>
BDAPPS_TIMEOUT=30
BDAPPS_VERIFY_SSL=true
BDAPPS_COUNTRY_CODE=880
BDAPPS_SUCCESS_STATUS_CODE=S1000

OPENROUTER_API_KEY=sk-or-...
OPENROUTER_BASE_URL=https://openrouter.ai/api/v1
OPENROUTER_MODEL=openai/gpt-4o-mini
OPENROUTER_TIMEOUT=60
OPENROUTER_APP_NAME="Chat App"
OPENROUTER_APP_URL=http://localhost
```

## Outstanding

- Crisis detection (deliberately skipped — only AI chat, no
  therapist).
- Push notifications.
- Cron-driven reconciliation job (sync local state with
  `getStatus`).
