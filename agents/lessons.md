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
