@extends('web.layouts.app', ['title' => 'Dashboard — ChatApp'])

@section('content')
    <div class="card status-card">
        <div>
            <h1>Subscription</h1>
            <p class="muted" style="margin-top:4px;">
                You're signed in as <strong>+880{{ $user->phone }}</strong>.
            </p>

            @if ($user->isSubscribed() && ! $isPaymentPending)
                <span class="badge badge-success">Active</span>
            @elseif ($isPaymentPending)
                <span class="badge badge-warning">Pending</span>
            @elseif ($awaitingOtp)
                <span class="badge badge-warning">Awaiting OTP</span>
            @else
                <span class="badge badge-muted">Not subscribed</span>
            @endif
        </div>
    </div>

    {{-- ────────────────────────── Active subscription ────────────────────────── --}}
    @if ($user->isSubscribed() && ! $isPaymentPending)
        <div class="card">
            <h2>Download the ChatApp</h2>
            <p class="muted">
                Your subscription is active. You can download the Android app below.
            </p>

            <dl class="meta">
                <dt>Status</dt>
                <dd>
                    @if ($subscription)
                        Gateway: <code>{{ $subscription->bdapps_subscription_status ?? 'REGISTERED' }}</code>
                    @else
                        Gateway: <code>REGISTERED</code>
                    @endif
                </dd>

                @if ($user->subscribed_at)
                    <dt>Active since</dt>
                    <dd>{{ $user->subscribed_at->format('Y-m-d H:i') }}</dd>
                @endif

                @if ($subscription?->started_at)
                    <dt>Subscription started</dt>
                    <dd>{{ $subscription->started_at->format('Y-m-d H:i') }}</dd>
                @endif
            </dl>

            <div class="actions">
                <a href="{{ route('downloads.apk') }}" class="btn btn-primary" download="chat-app.apk">
                    <span aria-hidden="true">↓</span>
                    Download ChatApp APK
                </a>

                <form action="{{ route('dashboard.unsubscribe') }}" method="POST"
                      onsubmit="return confirm('Cancel your subscription? You can re-subscribe any time.');">
                    @csrf
                    <button type="submit" class="btn btn-danger">Unsubscribe</button>
                </form>
            </div>
        </div>
    {{-- ─────────────────── Payment not confirmed (post-verify pending) ──────────────── --}}
    @elseif ($isPaymentPending)
        {{-- Auto-refresh while the 10s job + cron reconcile. Meta-refresh
             keeps the page no-JS and survives the user closing the tab
             then reopening. The user is signed in but the gateway has
             not yet confirmed REGISTERED — they can read this page,
             refresh, and navigate; the APK download remains gated
             (it's inside the subscribed branch above). --}}
        <meta http-equiv="refresh" content="{{ $refreshSeconds }}">

        <div class="card pending">
            <div class="pending-head">
                <div class="pending-spinner" aria-hidden="true"></div>
                <div>
                    <span class="badge badge-warning">Awaiting payment confirmation</span>
                    <h2 style="margin: 8px 0 2px;">Your payment is still pending</h2>
                </div>
            </div>

            <p class="muted" style="margin-top: 4px;">
                We've sent your subscription request to the gateway and they're confirming
                the payment on their side. This usually finishes within a few seconds.
                <strong>Please don't close this page.</strong> We'll auto-refresh, or you
                can press the button below to check now.
            </p>

            <div class="pending-steps" aria-label="What's happening">
                <div class="pending-step">
                    <span class="step-num">1</span> OTP verified ✓
                </div>
                <div class="pending-step">
                    <span class="step-num">2</span> Gateway charging your account…
                </div>
                <div class="pending-step">
                    <span class="step-num">3</span> Subscription becomes active
                </div>
            </div>

            @if ($subscription)
                <dl class="meta" style="margin-top: 14px;">
                    <dt>Gateway reports</dt>
                    <dd><code>{{ $subscription->bdapps_subscription_status ?? 'PENDING' }}</code></dd>

                    @if ($subscription->started_at)
                        <dt>Request sent</dt>
                        <dd>{{ $subscription->started_at->format('Y-m-d H:i') }}</dd>
                    @endif

                    @if ($subscription->last_notified_at)
                        <dt>Last update from gateway</dt>
                        <dd>{{ $subscription->last_notified_at->diffForHumans() }}</dd>
                    @endif
                </dl>
            @endif

            <div class="actions">
                <form action="{{ route('dashboard.refresh') }}" method="POST">
                    @csrf
                    <button type="submit" class="btn btn-primary">Refresh status now</button>
                </form>

                <form action="{{ route('logout') }}" method="POST">
                    @csrf
                    <button type="submit" class="btn btn-secondary">Sign out</button>
                </form>
            </div>
        </div>
    {{-- ─────────────────────── Awaiting OTP verification ─────────────────────── --}}
    @elseif ($awaitingOtp)
        <div class="card">
            <h2>Verify your OTP</h2>
            <p class="muted">
                We sent a one-time password to your phone. Enter it below to activate your subscription
                and unlock the APK download.
            </p>

            <form action="{{ route('dashboard.verify') }}" method="POST" novalidate>
                @csrf

                <label for="otp">One-time password</label>
                <input
                    id="otp"
                    type="text"
                    name="otp"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    pattern="[0-9]{4,6}"
                    minlength="4"
                    maxlength="6"
                    placeholder="1234"
                    required
                    autofocus
                >
                @error('otp')
                    <div class="field-error">{{ $message }}</div>
                @enderror

                <div class="actions">
                    <button type="submit" class="btn btn-primary">Activate subscription</button>

                    <form action="{{ route('dashboard.unsubscribe') }}" method="POST" style="display:inline;">
                        @csrf
                        <button type="submit" class="btn btn-danger">Cancel</button>
                    </form>
                </div>
            </form>
        </div>
    {{-- ────────────────────────── Not yet subscribed ─────────────────────────── --}}
    @else
        <div class="card">
            <h2>Subscribe</h2>
            <p class="muted">
                Subscribe to ChatApp to unlock the Android app and AI chat. We'll send a one-time
                password to your phone to confirm.
            </p>

            <form action="{{ route('dashboard.subscribe') }}" method="POST">
                @csrf
                <div class="actions">
                    <button type="submit" class="btn btn-primary">Subscribe via OTP</button>
                </div>
            </form>
        </div>
    @endif
@endsection
