# Project Plans

This file tracks every implementation plan produced for ChatApp backend.

## 2026-07-16 — Initial chat_app backend

**Feature:** Scaffold Laravel 13 backend with BDApps subscription +
streaming AI chat, mirroring cuno-therapy architecture.

**Prompt:** Strip cuno-therapy down to a single-user-type, two-screen
chat app. Keep the trait-based response envelope, Sanctum bearer auth,
layered Route→Service→Repository→Model architecture, and the `agents/`
memory tree. Replace therapist/patient split with a single subscriber.
Replace OTP-reset/email-password flows with phone-only OTP via BDApps.
Replace cuno-therapy's therapist webhooks with a Robi BDApps notify
webhook (constant-time secret compare).

**Plan:**

- [x] Install Sanctum + openai-php/laravel; install API routes.
- [x] Copy `JsonResponseTrait` + `ApiResponseTrait` + abstract base
      `Controller`.
- [x] Implement `BdAppsService` (CURL wrapper, subscriber-id
      normalisation matching quiz_app PHP shapes).
- [x] Implement `SubscriptionService` (startSubscription /
      ensureOtpReference / verifyOtp / cancelSubscription /
      applyNotifyStatus).
- [x] Implement `BdappsSubscriptionRepository`.
- [x] Update `User` model to phone-only with subscription fields.
- [x] Migrations: `users` (phone-based), `bdapps_subscriptions`,
      `chat_conversations`, `chat_messages`.
- [x] `config/bdapps.php`, `config/openrouter.php`.
- [x] FormRequests: `StartRequest`, `VerifyOtpRequest`,
      `StreamMessageRequest`.
- [x] `Api\Auth\AuthController` (start, verify, me, logout,
      unsubscribe).
- [x] `Webhook\BdAppsNotifyController` (constant-time secret compare).
- [x] `OpenRouterService` (OpenAI-compatible streaming).
- [x] `ChatService` (assemble messages, persist, stream).
- [x] `Api\Chat\ChatController` (SSE stream + history endpoint).
- [x] `routes/api.php` (public auth + sanctum group + webhook).
- [x] AGENTS.md, agents/Context.md, agents/api/*, agents/api/curl/*.
- [x] Unit tests: BdAppsServiceTest (normalisation + payload shape).
- [x] Feature tests: AuthControllerTest, ChatControllerTest,
      BdAppsNotifyControllerTest.

**Outcome:** Working backend skeleton ready for Flutter integration.
Run `php artisan migrate && php artisan serve` to start.

## 2026-07-17 — Login skip + SMS receive + milestone SMS

**Feature:** Passwordless login for trusted users; log-only SMS
Receive webhook; milestone SMS via BDApps /sms/send.

**Prompt:** No password on login — `subscribed` or `pending` users go
straight in. Utilize sms/send for the login-notify phase. Send an
SMS every 5 AI chats (5, 10, 15…). Add a webhook at
`/api/webhooks/bdapps/sms` that just logs.

**Plan:**

- [x] `BdAppsService::sendSms($phone, $message, …)` — POST
      `/sms/send` with the documented payload shape; logs every
      call to the `bdapps` channel with the password masked.
- [x] `config/bdapps.php` — add `sms_send_endpoint`,
      `sms_source_address`, `sms_delivery_status_request`,
      `sms_encoding`.
- [x] `env.example` — document `BDAPPS_SMS_*` plus the two
      feature flags `BDAPPS_LOGIN_SMS_NOTIFY_ENABLED` and
      `CHAT_MILESTONE_SMS_ENABLED`.
- [x] `SubscriptionService::notifyLogin(User)` — best-effort
      courtesy SMS for users we let skip OTP. Swallows
      `BdAppsException` / `ConnectionException` and logs to
      `bdapps`.
- [x] `AuthController::start` — short-circuit OTP for users with
      `subscription_status='subscribed'` OR a `pending` subscription
      row. Issues Sanctum token + invokes `notifyLogin()`.
- [x] Migration `2026_07_17_110518_create_chat_milestones_table`
      (composite unique `user_id, count`).
- [x] `App\Models\ChatMilestone` plus the `SmsService` that owns
      the milestone decision + send + record.
- [x] `ChatService::streamReply` — after persisting the assistant
      message, count assistant turns and call
      `SmsService::maybeNotifyMilestone`.
- [x] `Webhook\BdAppsSmsReceiveController` — log-only MO
      endpoint, mounted at `POST /api/webhooks/bdapps/sms`.
- [x] `agents/api/auth.md`, `agents/api/webhooks.md` updated.
- [x] `agents/api/curl/bdapps-sms-send.md` added.
- [x] `agents/lessons.md` — appended "Skip OTP for trusted users"
      entry.

**Outcome:** Trusted users no longer see the OTP step. Every 5th
AI chat fires an SMS (when enabled). Inbound keyword traffic is
captured to logs.

