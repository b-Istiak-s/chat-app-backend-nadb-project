# Web Dashboard — Browser subscription & APK download

Server-rendered dashboard at `/dashboard` lets a subscriber sign in,
subscribe, verify the OTP, download the APK, and unsubscribe entirely
from a browser.

All routes are mounted in `routes/web.php` and use Laravel's default
`web` guard (cookie session). They are independent of the Sanctum
bearer tokens used by the mobile app — a web session does not mint
an API token and vice-versa.

## Routes

| Method | Path | Notes |
|---|---|---|
| GET | `/` | Landing page. |
| GET | `/login` | Phone form (or OTP form when session has a pending phone). |
| POST | `/login/start` | Find-or-create user, send BDApps OTP, redirect to OTP step. |
| POST | `/login/verify` | Verify OTP, sign web session in, redirect to `/dashboard`. |
| POST | `/logout` | Sign web session out. |
| GET | `/dashboard` | Status card + subscribe/verify/unsubscribe controls. Auth required. |
| POST | `/dashboard/subscribe` | Kick OTP; set `awaiting_otp` flag in session. Auth required. |
| POST | `/dashboard/verify` | Verify OTP, mark user subscribed. Auth required. |
| POST | `/dashboard/unsubscribe` | Cancel BDApps subscription. Auth required. |
| GET | `/downloads/app.apk` | Stream the APK. Auth required AND user must be subscribed, else 403. |

## Browser flow — example CURL (cookie jar)

The dashboard is HTML-driven; these `curl` calls reproduce the
exact requests the browser issues (form posts with CSRF tokens).
A real user goes through `<form>` submissions; curl reproduction
just exists so we can test the controllers.

```bash
COOKIES=/tmp/chatapp-web.cookies

# 1) Land and grab a CSRF token from the login form.
curl -s -c $COOKIES -b $COOKIES "$APP_URL/login" \
  | grep -oP 'name="_token" value="\K[^"]+' \
  > /tmp/csrf

# 2) Submit the phone → kicks OTP, sets web_auth.phone in session.
curl -s -c $COOKIES -b $COOKIES -X POST \
  "$APP_URL/login/start" \
  -H "Referer: $APP_URL/login" \
  --data-urlencode "_token=$(cat /tmp/csrf)" \
  --data-urlencode "phone=01812345678"

# 3) Grab a fresh CSRF (Laravel rotates it after login/start) and
#    submit the OTP.
curl -s -c $COOKIES -b $COOKIES "$APP_URL/login" \
  | grep -oP 'name="_token" value="\K[^"]+' > /tmp/csrf

curl -s -c $COOKIES -b $COOKIES -L -X POST \
  "$APP_URL/login/verify" \
  -H "Referer: $APP_URL/login" \
  --data-urlencode "_token=$(cat /tmp/csrf)" \
  --data-urlencode "phone=01812345678" \
  --data-urlencode "otp=1234"

# 4) Dashboard landing.
curl -s -c $COOKIES -b $COOKIES "$APP_URL/dashboard"

# 5) Logged-in subscribe (kicks OTP for an already-authed user).
curl -s -c $COOKIES -b $COOKIES "$APP_URL/dashboard" \
  | grep -oP 'name="_token" value="\K[^"]+' > /tmp/csrf

curl -s -c $COOKIES -b $COOKIES -X POST \
  "$APP_URL/dashboard/subscribe" \
  -H "Referer: $APP_URL/dashboard" \
  --data-urlencode "_token=$(cat /tmp/csrf)"

# 6) Unsubscribe.
curl -s -c $COOKIES -b $COOKIES -X POST \
  "$APP_URL/dashboard/unsubscribe" \
  -H "Referer: $APP_URL/dashboard" \
  --data-urlencode "_token=$(cat /tmp/csrf)"

# 7) APK download (only when subscribed).
curl -s -c $COOKIES -b $COOKIES -OJ \
  "$APP_URL/downloads/app.apk"
```

## Gating rules

- `/dashboard/*` and `/downloads/app.apk` require an authenticated
  `web` session. Unauthenticated requests are 302-redirected to
  `/login` by Laravel's `auth` middleware.
- `/downloads/app.apk` additionally requires
  `$user->isSubscribed() === true`. Unsubscribed users hit a 403
  rendered by the dashboard view.

## Implementation files

- Routes: `routes/web.php`
- Controllers: `app/Http/Controllers/Web/{WebAuthController,DashboardController}.php`
- FormRequests: `app/Http/Requests/Web/Auth/{StartRequest,VerifyOtpRequest}.php`
- Views: `resources/views/web/{layouts/app,auth/login,dashboard,partials/flash}.blade.php`
- APK location: `storage/app/public/downloads/app-debug.apk`
- Business logic reused unchanged: `App\Services\BdApps\SubscriptionService`
