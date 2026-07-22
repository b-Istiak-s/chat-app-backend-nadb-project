@extends('web.layouts.app', ['title' => 'Dashboard — ChatApp'])

@section('content')
    <div class="card status-card">
        <div>
            <h1>Subscription</h1>
            <p class="muted" style="margin-top:4px;">
                You're signed in as <strong>+880{{ $user->phone }}</strong>.
            </p>

            @if ($user->isVerified() && ! $hasPendingCharge)
                <span class="badge badge-success">Active</span>
            @elseif ($user->isVerified() && $hasPendingCharge)
                <span class="badge badge-warning">Payment pending</span>
            @elseif ($awaitingOtp || $user->isAwaitingOtp())
                <span class="badge badge-warning">Awaiting OTP</span>
            @else
                <span class="badge badge-muted">Not subscribed</span>
            @endif
        </div>
    </div>

    {{-- ────────────────────────── Active subscription ────────────────────────── --}}
    @if ($user->isVerified() && ! $hasPendingCharge)
        <div class="card">
            <h2>Download the ChatApp</h2>
            <p class="muted">
                Your subscription is active. You can download the Android app below.
            </p>

            <dl class="meta">
                <dt>Gateway reports</dt>
                <dd>
                    <code>{{ $subscription?->bdapps_subscription_status ?? 'REGISTERED' }}</code>
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

    {{-- ──────────────────────────── Payment pending (post-verify) ─────────────────────────── --}}
    @elseif ($user->isVerified() && $hasPendingCharge)
        {{-- Auto-refresh while the 10s job + cron reconcile. Meta-refresh
             keeps the page no-JS and survives the user closing the tab
             then reopening. The user is signed in but the gateway has
             not yet taken the money — they see this view. The APK
             download is gated (it's inside the Active branch above). --}}
        <meta http-equiv="refresh" content="{{ $refreshSeconds }}">

        <div class="card pending">
            <div class="pending-head">
                <div class="pending-spinner" aria-hidden="true"></div>
                <div>
                    <span class="badge badge-warning">Payment pending</span>
                    <h2 style="margin: 8px 0 2px;">Payment pending — waiting for confirmation</h2>
                </div>
            </div>

            <p class="muted" style="margin-top: 4px;">
                <strong>Payment not confirmed.</strong> Your subscription is waiting for
                BDApps to confirm the payment on their side. This usually finishes
                within a few seconds. Please don't close this page — we'll auto-refresh,
                or you can press the button below to check now.
            </p>

            <div class="alert" role="alert" style="margin-top: 14px; background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.25); color: #fca5a5;">
                <strong>Low balance?</strong> If your mobile balance is too low, the
                gateway can't charge you and this page will keep waiting.
                <strong>Recharge your account</strong> and press <em>Refresh status now</em>
                below.
            </div>

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

    {{-- ────────────────────────── Awaiting OTP verification ────────────────────────── --}}
    @elseif ($awaitingOtp || $user->isAwaitingOtp())
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

                    <a href="{{ route('logout') }}" class="btn btn-secondary"
                       style="text-decoration:none; padding: 12px 22px;">
                        Sign out
                    </a>
                </div>
            </form>
        </div>

    {{-- ────────────────────────── Cancelled or fresh ────────────────────────── --}}
    @else
        <div class="card">
            @if ($user->isCancelled())
                <h2>Your previous subscription was cancelled</h2>
                <p class="muted">
                    You can re-subscribe below — we'll send a fresh OTP to your phone.
                </p>
            @else
                <h2>Subscribe</h2>
                <p class="muted">
                    Subscribe to ChatApp to unlock the Android app and AI chat. We'll send a one-time
                    password to your phone to confirm.
                </p>
            @endif

            <form action="{{ route('dashboard.subscribe') }}" method="POST">
                @csrf
                <div class="actions">
                    <button type="submit" class="btn btn-primary">
                        {{ $user->isCancelled() ? 'Re-subscribe via OTP' : 'Subscribe via OTP' }}
                    </button>
                </div>
            </form>
        </div>
    @endif
@endsection
