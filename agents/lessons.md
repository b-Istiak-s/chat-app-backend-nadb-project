# Lessons Learned

## 2026-07-16 — Subscriber-id normalisation

**What was flawed:** Initial implementation of
`BdAppsService::formatSubscriberId()` produced `tel:88001812345678` for
local input `01812345678` — an extra `0` between the country code and
the subscriber number.

**Root cause:** The 11-digit branch returned
`tel:{country}{digits}` without first stripping the local leading 0.
Quoting cuno-therapy's pattern would have caught this earlier — its
implementation drops the leading 0 before prepending the country code.

**Correction:** Now `tel:{country}{digits_without_leading_0}` for the
local 11-digit case. Test suite locks the four supported input shapes.

**Check next time:** When normalising a phone with `0XXXXXXXXXX` form,
**always** strip the `0` before prepending the country code. Add a unit
test covering each accepted input shape before merging.

## 2026-07-16 — SSE streaming requires `X-Accel-Buffering: no`

**What was flawed:** First SSE response sat idle in the buffer
because nginx (and many reverse proxies) buffer chunked responses by
default.

**Correction:** Set `X-Accel-Buffering: no` and `Cache-Control: no-cache,
no-transform` on every `text/event-stream` response. Also call
`ob_flush(); flush();` after every `echo`.

**Check next time:** Any time we add a new SSE endpoint, verify the
four headers are present (`Content-Type`, `Cache-Control`,
`X-Accel-Buffering`, `Connection`).

## 2026-07-16 — BDApps notify secret is *not* the same as `BDAPPS_PASSWORD`

**What was flawed:** The webhook was authenticating against
`config('bdapps.password')` (the encoded app hash used by the OTP
endpoints). That's a separate secret and should not double as the
webhook shared secret.

**Correction:** Added a dedicated `BDAPPS_NOTIFY_SECRET` env var.
Webhook validates it via `hash_equals` against the `X-Bdapps-Secret`
header (or `notify_secret` body field as fallback).

**Check next time:** Never overload the same credential across two
different surfaces (gateway auth vs webhook auth). Each surface should
have its own rotating secret.

## 2026-07-16 — Dedicated exception type for gateway errors

**What was flawed:** Catching `\Throwable $e` and reading
`$e->getMessage()` for BDApps failures loses the structured fields
(`statusCode` like `E1312`, `statusDetail` like "Request is Invalid.")
the gateway gives us. Trying to parse them back out of the message
string is fragile.

**Correction:** `BdAppsService` now throws `App\Exceptions\BdApps\
BdAppsException` whenever the gateway returns `ok=false`. The
exception carries public readonly `statusCode`, `statusDetail`, and
`httpStatus` properties. Callers (`SubscriptionService`) catch
`BdAppsException` and log structured fields directly:
`['phone' => ..., 'status_code' => $e->statusCode, 'status_detail'
=> $e->statusDetail]`.

**Check next time:** When wrapping any third-party API, prefer a
dedicated exception class with typed properties over a generic
`\Throwable`. It makes structured logging and conditional catch
blocks trivial.

## 2026-07-16 — Inline `Log::channel(...)` over `$this->log()` helpers

**What was flawed:** A `protected function log()` helper that
resolved `Log::channel(config('bdapps.log_channel', 'bdapps'))` felt
DRY but obscured which channel each entry actually landed in. With
the helper in place you had to scroll up to confirm whether a given
`->error()` call was going to `stack`, `bdapps`, or somewhere else.

**Correction:** All BDApps log sites now use inline
`Log::channel('bdapps')->error(...)` (or `info`/`warning`). The
channel name is right there at the call site, so the destination is
self-evident.

**Check next time:** Only reach for a logging helper when the channel
is genuinely dynamic per call (e.g. resolved from a per-tenant
config). For everything else, spell the channel out at the call site.

## 2026-07-17 — `INITIAL CHARGING PENDING` is not yet `REGISTERED`

**What was flawed:** `SubscriptionService::verifyOtp` flipped the user
to `subscribed` for **any** non-throwing gateway response. The Robi
gateway answers `/subscription/otp/verify` with HTTP 200 +
`statusCode: S1000` even when the subscription is still being
processed — the `subscriptionStatus` field carries
`INITIAL CHARGING PENDING` in that case. Treating it as success
silently granted a Sanctum token for a subscription that may never
activate.

**Correction:** `verifyOtp` now branches on `subscriptionStatus`:

- `REGISTERED` → row `status='registered'`, user `subscribed`.
- `INITIAL CHARGING PENDING` / `CHARGE_PENDING` / `PENDING` (via
  `BdAppsService::PENDING_STATUSES`) → user optimistically
  `subscribed` (token still issued, per product decision), row stays
  at `status='pending'` so the `bdapps:poll-pending` cron can
  reconcile later.
- Anything else → default to `registered` (defensive; gateway already
  accepted the verify).

**Check next time:** Any verify / status response must inspect
`subscriptionStatus` — gateway success codes (`S1000`) only mean the
HTTP round-trip worked, not that the user's subscription is active.
A "still charging" branch is mandatory for any async subscription
flow.

## 2026-07-17 — Persist the gateway's base64 `subscriberId`

**What was flawed:** `bdapps_subscriptions.subscriber_id` only held
the locally-derived `tel:880…` form. The Robi gateway, however, treats
the base64 wire identifier it returns from verify / getStatus /
notify (e.g. `tel:ZWRhY2Y5N2Y…`) as the canonical subscriber id.
Sending the locally-derived form back to `/subscription/send` works
in practice but is a mismatch the gateway docs explicitly flag.

**Correction:** A new column `bdapps_subscriptions.gateway_subscriber_id`
holds the base64 form once the gateway reveals it (typically on
verify). `BdAppsService::unsubscribe()`,
`SubscriptionService::cancelSubscription()` and the
`bdapps:poll-pending` cron all prefer `gateway_subscriber_id` when
present and fall back to the `tel:880…` form derived from the phone.

**Check next time:** Any time the gateway returns a `subscriberId` in a
response body, persist it as-is. Don't try to parse base64 or
re-derive it from the phone — the gateway is the source of truth for
its own identifier.
