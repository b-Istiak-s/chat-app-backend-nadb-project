# ChatApp Backend — AGENTS.md

<project-guidelines>

## Planning First (Mandatory)

Before implementing any feature:

1. Analyze the feature requirements.
2. Create an implementation plan.
3. Append the plan to `agents/plans.md` before making any code changes.
4. Create a task checklist.

Implementation must not begin before the plan is recorded.

---

## Required Request Lifecycle

Every feature follows:

```
Route → Middleware → Validation (FormRequest) → Controller → Service → Repository → Model
```

- Never bypass the service layer.
- Never access repositories directly from controllers.
- Never place business logic inside controllers.

---

## Single-user-type rule

This app has **only one user type** (subscriber). No role middleware,
no therapist/patient split. Drop `CheckRole` and any role-based
middleware. Sanctum bearer auth is the only access control.

---

## API Security

All routes require Sanctum authentication **except**:

- `POST /api/auth/start`           (find-or-create user + request OTP)
- `POST /api/auth/verify`          (verify OTP + mark subscribed)
- `POST /api/webhooks/bdapps/notify` (uses shared notify_secret)
- `GET  /up`                       (health probe)

Everything else requires `Authorization: Bearer <token>`.

---

## API Response Rules

All responses use the standard envelope (see `app/Traits/ApiResponses/JsonResponseTrait.php`):

```json
{ "success": true|false, "message": "Human readable", "data": {...} | null, "errors": {...}? }
```

Controllers **must** call `sendSuccessResponse(...)`, `sendErrorResponse(...)`,
or `sendValidationErrorResponse(...)` — never return raw models.

---

## Migration Rules

Never modify existing migration files. Create a new one for schema
changes. Always preserve migration history.

---

## Foreign Key Rules

Foreign keys must be explicitly defined:

```php
$table->unsignedBigInteger('user_id');
$table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
```

Do not rely on implicit relationships.

---

## CURL Documentation Rules

Every endpoint that talks to an external service must have a CURL
example under `agents/api/curl/`. Each file follows the cuno-therapy
template:

```md
## METHOD https://external-host/path

Brief description.

| field | type | required | description |
|---|---|---|---|

###### METHOD /api/path

\```curl
curl \
  -X METHOD \
  "$APP_URL/api/path" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ ... }'
\```
```

CURL docs cover:

- The endpoint URL (full, with scheme + host).
- HTTP method.
- Required vs optional headers.
- Body fields with types and constraints.
- Sample success + failure responses (HTTP code + JSON).

---

## SSE Streaming Rules

When an endpoint streams via SSE (`Content-Type: text/event-stream`):

- Yield `data: <json>\n\n` per chunk. Each line MUST end with `\n\n`.
- Include `Cache-Control: no-cache, no-transform` and
  `X-Accel-Buffering: no` so reverse proxies don't buffer.
- Persist the user message **before** streaming starts.
- Persist the assistant message **after** the stream completes.
- Always end with `data: {"done":true,"message_id":<id>}\n\n`.
- On error, emit `data: {"error":"..."}\n\n` and close the stream.

---

## Lessons Learned

Every cleanup, bug fix, refactor, or corrective change updates
`agents/lessons.md` with: what was flawed, how it was corrected,
what to check next time.

---

## Architecture

- Repository = Data Access
- Service = Business Workflow
- Controller = Request Handling

```
Controller → Service → Repository → Model
```

Repositories are role-agnostic. Authorization lives in controllers
and middleware (in our case, only Sanctum).

</project-guidelines>

<laravel-boost-guidelines>

## Laravel Boost

This project uses Laravel Boost MCP. Prefer Boost tools over manual
alternatives. Use `database-query` for read-only DB queries,
`database-schema` to inspect schema, and `get-absolute-url` for URLs.

## PHP Conventions

- Use curly braces for all control structures.
- PHP 8 constructor property promotion.
- Explicit return type declarations and parameter type hints.
- PHPDoc over inline comments.

## Testing

- Use PHPUnit (`php artisan make:test --phpunit {name}`).
- Tests cover happy paths, failure paths, and edge cases.
- Run the minimal number of tests before finalizing.

## Pint

Run `vendor/bin/pint --dirty --format agent` after modifying PHP files.

</laravel-boost-guidelines>

<git-workflow>

## Git Workflow

- **No `git push`** without explicit user approval.
- **One repo per concern**: this project is the **backend** repo at
  `backend/`. The Flutter client lives in a separate repo at `app/`.
- **Commit modularly**: split work into logical, single-purpose
  commits so each one is independently reviewable. Typical modules
  in this project: scaffold → traits → config → bdapps services →
  db migrations → models → openrouter service → auth controller →
  chat controller → webhook controller → routes/provider → tests →
  docs. Squash only inside a single module if you must; never squash
  across modules.
- **Never commit secrets.** `.env`, `.env.testing` (which uses fake
  values, but still), API keys, production passwords, etc. must
  stay out of the index. The repo's `.gitignore` covers `.env`; the
  docs in `agents/api/curl/*.md` use the `<BDAPPS_PASSWORD>`
  placeholder. If you find a leaked secret, redact in a follow-up
  commit AND rotate the secret out-of-band.
- **Commit messages**: imperative subject line, blank line, body
  explaining *why*. Co-authored-by Sonnet 4.6 <noreply@puku.sh> on
  every commit. Format example:
  ```
  feat(bdapps): add BdAppsService + SubscriptionService + repo

  BdAppsService — thin CURL wrapper around the Robi BDApps gateway...
  ```

</git-workflow>
