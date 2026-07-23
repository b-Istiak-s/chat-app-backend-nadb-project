# API Changelog

## 2026-07-23 — v2

### State model: `cancelled` → `unregistered`

The local subscription state name now matches the gateway's
literal `UNREGISTERED` reply. Same set of states (unverified /
pending / registered / unregistered), one canonical name.

| Old                | New            | Effect                                       |
|--------------------|----------------|----------------------------------------------|
| `subscription_status = 'cancelled'` | `subscription_status = 'unregistered'` | User cancelled, or gateway returned `UNREGISTERED` / `EXPIRED` / `TEMPORARY BLOCKED`. |
| `bdapps_subscriptions.status = 'cancelled'` | `bdapps_subscriptions.status = 'unregistered'` | Mirror of the user status on the subscription row. |

Existing rows are rewritten in-place by the migration
`2026_07_23_130000_rename_cancelled_to_unregistered.php` —
no rows destroyed, defaults unchanged.

### `/api/auth/me` forced-logout gate

`/api/auth/me` now refuses to honour a Sanctum token when the
user is no longer token-bearing. On a mismatch it:

1. Deletes every Sanctum token for the user (`tokens()->delete()`).
2. Returns 401 with an `error_code` discriminator:
   - `forced_logout` — the row was flipped to `unregistered`
     by background reconciliation (BDApps webhook, per-user
     poll, or cron).
   - `subscription_required` — the user was `unverified` /
     had no row (edge case; no token should exist).

This catches background subscription flips within seconds of
the client calling `/auth/me` (which it does on every launch
and focus event).

### `TEMPORARY BLOCKED` is terminal

`BdAppsService::TERMINAL_FAILURE_STATUSES` adds
`TEMPORARY BLOCKED`. A reply of `TEMPORARY BLOCKED` from
`/otp/verify` or `/getStatus` is treated like `UNREGISTERED`:
row + user → `unregistered`, no token issued, next
`/auth/start` will see whether the gateway has unblocked
yet. New `BdAppsService::isTemporaryBlocked()` helper for
the UI / logs to surface the operator-applied status
distinctly.

Also fixes a latent bug in `SubscriptionService::verifyOtp()`
where the row was unconditionally set to `pending` on a
terminal reply.

### Brand rename

Product display name `ChatApp` → `Chat App`. Affects:

- `APP_NAME`, `BDAPPS_APPLICATION_HASH`, `OPENROUTER_APP_NAME`
  in `.env` / `.env.example` / `.env.testing`.
- OpenRouter `X-Title` header fallback.
- Login-confirmation SMS body.
- All blade view titles, H1/H2s, navbar brand, meta description.

---

## 2026-07-16 — v1 (initial)

### Added

- `POST /api/auth/start` — find-or-create user, request BDApps OTP.
- `POST /api/auth/verify` — verify OTP, mark subscribed, issue token.
- `GET  /api/auth/me` — current user + subscription status.
- `POST /api/auth/logout` — revoke Sanctum token.
- `POST /api/auth/unsubscribe` — cancel BDApps subscription.
- `POST /api/chat/messages` — SSE stream of AI chat reply.
- `GET  /api/chat/messages` — paginated chat history.
- `POST /api/webhooks/bdapps/notify` — Robi BDApps notify webhook.

### Conventions

- Single user type — no role middleware.
- Sanctum bearer auth on all routes except `/auth/start`, `/auth/verify`,
  and `/webhooks/bdapps/notify`.
- BDApps OTP flow replaces email/password login.
- SSE streaming for AI chat (no one-shot calls).
