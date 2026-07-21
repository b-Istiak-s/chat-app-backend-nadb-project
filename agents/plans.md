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

## 2026-07-20 — Web dashboard + subscription gate + APK download

**Feature:** Add a server-rendered web dashboard so subscribers can
sign up / subscribe / unsubscribe from a browser, and so the APK
download is gated behind an active subscription (the existing
`landing.blade.php` currently serves the APK unconditionally to
anyone).

**Prompt:** Update the backend/web to make a dashboard page.
Basically allow subscription through the web and just allow APK
download after the subscription. Also allow unsubscription through
the web.

**Plan:**

### Routes (`routes/web.php`)

- Public:
  - `GET /` — landing page (existing `landing.blade.php`, kept as-is
    but the download button now points at the gated route).
  - `GET /login` — phone + OTP login form (web auth).
  - `POST /login/start` — find-or-create user, kick BDApps OTP.
  - `POST /login/verify` — verify OTP, sign web session in.
  - `POST /logout` — sign web session out.
- Authenticated web session (`auth` middleware, `web` guard):
  - `GET /dashboard` — status card (phone, subscribed/unsubscribed),
    subscribe / unsubscribe controls, APK download button when
    subscribed.
  - `POST /dashboard/subscribe` — kicks OTP flow if not already
    pending, otherwise shows the OTP form (same flow as mobile).
  - `POST /dashboard/verify` — verify OTP, mark subscribed.
  - `POST /dashboard/unsubscribe` — cancel BDApps subscription.
  - `GET /downloads/app.apk` — gates and streams the APK only to
    subscribed users (replaces the public `public/downloads/` link).

### Controllers (`app/Http/Controllers/Web/`)

- `DashboardController`
  - `index(Request)` — show dashboard with current subscription state.
  - `subscribe(Request)` — call `SubscriptionService::startSubscription`;
    render the OTP step when a `reference_no` comes back.
  - `verifyOtp(Request)` — call `SubscriptionService::verifyOtp`;
    on success the user is `subscribed` and sees the download button.
  - `unsubscribe(Request)` — call `SubscriptionService::cancelSubscription`.
  - `downloadApk(Request)` — if `! $user->isSubscribed()` abort 403;
    else `return response()->download($apkPath, 'chat-app.apk')`.
- `WebAuthController`
  - `showLogin()` — render phone + (optionally) OTP form.
  - `start(Request)` — same semantics as mobile `start`.
  - `verify(Request)` — verify OTP + `Auth::guard('web')->login($user)`.
  - `logout(Request)` — `Auth::guard('web')->logout()`.

### Form Requests

- `Web\Auth\StartRequest` (FormRequest) — phone regex reused.
- `Web\Auth\VerifyOtpRequest` — phone + otp regex reused.
- `Web\Dashboard\SubscribeRequest` — empty (re-uses start flow).
- `Web\Dashboard\VerifyOtpRequest` — phone + otp.
- `Web\Dashboard\UnsubscribeRequest` — empty (POST + session confirm).

### Services

- No new business services needed — `SubscriptionService` is already
  the canonical entry point. We only add thin web controllers that
  delegate to it. (Per AGENTS.md: "Never bypass the service layer.
  Never access repositories directly from controllers.")

### Views (`resources/views/web/`)

- `layouts/app.blade.php` — minimal shared layout (Tailwind CDN
  allowed, kept light; no Vite build step needed for this scope).
- `auth/login.blade.php` — phone input → OTP input (same form,
  progressive disclosure via `@if($referenceNo)`).
- `dashboard.blade.php` — three states:
  - unsubscribed → "Subscribe" button.
  - pending / awaiting OTP → OTP form.
  - subscribed → status banner + Download APK button + Unsubscribe.
- `partials/flash.blade.php` — flash-message renderer.

### Middleware / config

- Web session guard already exists (Laravel default). No new
  middleware needed — `auth` on the route group covers it.
- Register the APK download route under the `web` group so session
  auth applies.

### APK gating

- The current public asset (`public/downloads/app-debug.apk`,
  ~84 MB) becomes orphaned. Move the artefact to
  `storage/app/public/downloads/app-debug.apk` (so it lives behind
  `storage/app/` rather than the public webroot). The dashboard
  download controller streams from that path. Direct requests to
  `/downloads/app-debug.apk` 404 because the file is no longer in
  `public/`.
- The landing page's `download-app.apk` href is rewritten to point
  at the gated route (`/downloads/app.apk`).

### Lessons to capture (`agents/lessons.md`)

- Web session vs Sanctum token: web uses Laravel's `web` guard (cookie
  session); the mobile app uses Sanctum bearer tokens. They are
  independent — issuing a web session does NOT mint a Sanctum token
  and vice-versa. Note this in lessons.md so future contributors do
  not assume a single auth surface.

### Files touched

- New: `routes/web.php` (extended), `app/Http/Controllers/Web/*`,
  `app/Http/Requests/Web/*`, `resources/views/web/**`.
- Modified: `resources/views/landing.blade.php` (download href).
- Moved: `public/downloads/app-debug.apk` →
  `storage/app/public/downloads/app-debug.apk`.
- Docs: `agents/plans.md` (this entry), `agents/lessons.md`
  (web-session note + APK gating note).

### Validation

- `php artisan view:cache` then manual run-through of:
  1. `GET /` → landing page.
  2. `GET /dashboard` while logged out → redirected to `/login`.
  3. `POST /login/start` with a known test phone → OTP form shown.
  4. `POST /login/verify` → dashboard with active state.
  5. `GET /downloads/app.apk` while subscribed → 200 + APK bytes.
  6. Unsubscribe then re-attempt the download → 403.
  7. Log out → `/dashboard` redirects back to `/login`.
- `vendor/bin/pint --dirty --format agent`.

**Outcome:** Server-rendered dashboard lets a user subscribe,
verify OTP, download the APK, and unsubscribe entirely from a
browser. The APK is no longer a static public asset.

## 2026-07-20 — Strict activation + per-user 10s reconcile job

**Feature:** Make login require a *fully paid* subscription, not
just an in-flight one. After a successful OTP verify, schedule a
per-user delayed job that finalises activation. Render an
auto-refreshing "Activating…" dashboard view while the user waits.

**Prompt:** Commit modularly. When subscription status is `pending`,
don't allow login — user can only log in when paid, not during
pending. After successful verification, create a queue which will
check 10 seconds later (checkStatus-like — poll the gateway).

**Plan:**

### Why this change exists

Previously `SubscriptionService::verifyOtp()` was optimistic — it
flipped `users.subscription_status = 'subscribed'` even when the
gateway answered `INITIAL CHARGING PENDING`. Combined with the
pending-row branch in `userCanSkipOtp()`, a freshly-charged-but-
unregistered subscriber could log in (and download the APK). That's
not what "subscribed" means.

### Routes / controllers / services — modified

- `app/Services/BdApps/SubscriptionService.php`
  - `verifyOtp()` drops the optimistic activation. Only
    `REGISTERED` flips the user to `subscribed`; pending statuses
    keep them at `unsubscribed` with the row at `pending`.
  - New `finalizeActivation(User $user): ?BdappsSubscription` —
    one-shot `/getStatus` + `applyNotifyStatus()`. Reusable from
    the Job and the dashboard's "Refresh now" button.
- `app/Http/Controllers/Api/Auth/AuthController.php`
  - `verify()` returns HTTP 202 with `{token: null,
    requires_activation: true}` when the gateway response is not
    `REGISTERED`. Dispatches `PollSubscriptionStatusJob` regardless
    (REGISTERED path — caches the canonical subscriber id).
  - `userCanSkipOtp()` reduced to `return $user->isSubscribed();`.
  - Imports updated (drop unused `BdappsSubscription`).
- `app/Http/Controllers/Web/WebAuthController.php`
  - Mirrors the API changes. Pending branch in `userCanSkipOtp`
    deleted; verify() logs the user in but redirects to the
    dashboard, where the auto-refresh view carries them through.
- `app/Http/Controllers/Web/DashboardController.php`
  - `index()` computes `isActivating = ! isSubscribed() &&
    latest row is pending`, passes it to the view along with the
    refresh interval from config.
  - `verify()` returns the right flash string depending on whether
    the gateway said `REGISTERED` or pending.
  - New `refreshStatus()` action — POST `/dashboard/refresh`.
    Calls `finalizeActivation()` synchronously.
  - `downloadApk()` unchanged (still strict on `isSubscribed()`).
- `app/Http/Controllers/Web/DashboardController.php` imports
  `BdappsSubscription` (new — for `STATUS_PENDING` in `index()`).

### Files created

- `app/Jobs/PollSubscriptionStatusJob.php` — `ShouldQueue`,
  `tries=3`, `backoff=[10, 30, 60]`, `timeout=60`.
  Captures `int $userId` (by value, not by Eloquent model).
  `failed(\Throwable)` logs to the `bdapps` channel; the cron is
  the backstop.

### Config

- `config/bdapps.php`
  - `delayed_getstatus_seconds` (default 10, env
    `BDAPPS_DELAYED_GETSTATUS_SECONDS`).
  - `pending_refresh_seconds` (default 5, env
    `BDAPPS_PENDING_REFRESH_SECONDS`).
  - Both follow the established `config(...)`-not-`env(...)` rule
    (see earlier lesson: "env() outside config files silently
    returns null in prod").
- `.env.example` documents both flags with explanatory comments.
- `app/Console/Commands/PollPendingBdappsSubscriptionsCommand.php`
  - Default `--minutes` drops from 5 to 1.
- `app/Repositories/BdappsSubscriptionRepository.php`
  - `pendingForPolling()` now actively filters on `started_at`
    (the inline comment notes the predicate is monotonic — a row
    keeps re-qualifying every cron tick after the cutoff, never
    ages out).

### Routes

- `routes/web.php` adds `POST /dashboard/refresh` →
  `dashboard.refresh`.

### View

- `resources/views/web/dashboard.blade.php`:
  - New `elseif ($isActivating)` branch with
    `<meta http-equiv="refresh" content="{{ $refreshSeconds }}">`
    so the page no-JS auto-refreshes until the user is fully
    subscribed.
  - APK download button + unsubscribe form remain strictly inside
    the `@if ($user->isSubscribed())` branch — pending users
    cannot reach them.
  - "Refresh status now" button calls the new POST endpoint.

### Docs

- `agents/lessons.md` — two new entries:
  - "Pending must not grant access; only registered may".
  - "The 10-second delayed job is the new primary reconcile; the
    cron is the safety net."
- `agents/Context.md` — endpoint table updated.

### Verification done

- `php -l` clean on every modified PHP file.
- `php artisan view:cache` succeeds.
- `php artisan route:list` shows `/dashboard/refresh`.
- Live smoke test: `/`, `/login`, `/dashboard`, `/downloads/app.apk`
  (gated), `/dashboard/refresh` (gated by `auth`). All return
  expected codes (200/302/302).
- `vendor/bin/pint --dirty --format agent` passes.

### Modular commit history

1. `feat(auth): strict activation — login gated on fully-paid subscriptions`
   — SubscriptionService + API/Web AuthControllers.
2. `feat(bdapps): per-user 10s PollSubscriptionStatusJob + cron safety net`
   — Job + config flags + cron + repository age filter + dispatch
     from both controllers.
3. `feat(web): dashboard activating view + refresh button`
   — DashboardController `isActivating` state + new
     `/dashboard/refresh` route + Blade branch + meta-refresh
     view.

**Outcome:** Login is now meaningful — only a fully-paid user gets
in. Activation flow uses a 10-second delayed job for the user's
UX and a 5-minute cron as the safety net. Dashboard renders an
auto-refreshing "Activating…" page while the user waits.

## 2026-07-21 — Soft activation reversal

**Feature:** Revert the strict-activation model (rejected by user
on review). Allow login once the gateway accepts the OTP; show a
"Payment not confirmed" page that polls until the row flips to
`registered`, then let the user continue into the chat / APK
download.

**Prompt:** "allow login but show a page, saying that payment
wasn't confirmed. Then it will keep polling...the backend to
see if the user has successfully registered. Finally, if they
are just then they can continue to use the service." + "do it
on both backend and app".

**Plan:**

### Why this change exists

The previous round ("Strict activation + per-user 10s reconcile
job") had the right principle but the wrong implementation.
The HTTP 202 / no-token branch for pending gateway responses
prevented the user from reading the page that told them what
was happening — they had no session, no token, no way to
refresh. The "auth surface stays open, service surface stays
gated" model is the right shape: any user with a Sanctum token
(or web session) can navigate, refresh, read the "Payment not
confirmed" view; the chat / APK download stay gated on a
fully-confirmed row.

### Services / controllers / jobs — modified

- `app/Services/BdApps/SubscriptionService.php`
  - `verifyOtp()` flips `users.subscription_status =
    'subscribed'` on ANY non-empty gateway response (REGISTERED,
    INITIAL CHARGING PENDING, CHARGE_PENDING, PENDING). The row
    mapping is unchanged.
- `app/Http/Controllers/Api/Auth/AuthController.php`
  - `verify()` always issues a Sanctum token on success. The
    `requires_activation` / HTTP 202 branch is gone. Response
    shape is back to `{token, subscription_status}` + 200. The
    `PollSubscriptionStatusJob` dispatch from `verify()` is
    dropped (the dashboard's meta-refresh / "Refresh now"
    + the cron + the existing 10-second job (still dispatched
    from the web/dashboard side, when applicable) cover
    reconciliation).
  - `me()` adds `is_payment_pending` to the response payload.
  - `userCanSkipOtp()` is back to trusting `subscribed` OR
    `pending` rows.
- `app/Http/Controllers/Web/WebAuthController.php`
  - `verify()` always signs the user in. The conditional
    branch on `subscription_status !== 'REGISTERED'` is gone.
  - `userCanSkipOtp()` mirrors the API rule.
- `app/Http/Controllers/Web/DashboardController.php`
  - `index()` computes `isPaymentPending =
    isSubscribed() && isPaymentPending()`. The view receives a
    renamed flag.
  - `verify()` returns the "Subscription activated" flash
    string unconditionally — the row state drives the rendered
    view.
- `app/Models/User.php`
  - New `isPaymentPending()` helper.
- `app/Jobs/PollSubscriptionStatusJob.php`
  - Kept. No behaviour change — it still polls `/getStatus`
    and applies via `applyNotifyStatus()`. The dashboard's
    meta-refresh rides on top of it.

### View

- `resources/views/web/dashboard.blade.php`:
  - Renamed `elseif ($isActivating)` branch to "Payment not
    confirmed" with matching copy. Pill label changes from
    "Activating…" to "Payment not confirmed". The
    "Refresh status now" button + sign-out form stay.

### Modular commit history

1. `feat(auth): soft activation — service surface gates on row state`
   — SubscriptionService + API/Web AuthControllers +
     DashboardController + User model + dashboard view.
2. `feat(auth): /api/auth/me returns is_payment_pending`
   — User endpoint surfaces the row state to mobile clients.

**Verification:**

- `php -l` clean on every modified PHP file.
- `php artisan view:cache` succeeds.
- `php artisan route:list` unchanged.
- Live smoke test: `/login` → `/dashboard` (payment-not-confirmed
  branch) → "Refresh status now" (or wait for cron) → `/dashboard`
  (subscribed branch) → `/downloads/app.apk` (gated).
- `vendor/bin/pint --dirty --format agent` passes.

**Outcome:** Auth surface stays open; service surface stays gated.
The user is signed in once the gateway accepts the OTP. While the
row is still `pending`, the dashboard renders the "Payment not
confirmed" view with auto-refresh and a manual "Refresh now"
button. The 10-second delayed `PollSubscriptionStatusJob` and the
cron safety net drive row reconciliation in the background — login
no longer depends on them.
