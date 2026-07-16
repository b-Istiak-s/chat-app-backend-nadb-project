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
