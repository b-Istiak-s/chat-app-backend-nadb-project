# API Changelog

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
