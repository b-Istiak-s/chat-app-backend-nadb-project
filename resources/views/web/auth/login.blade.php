@extends('web.layouts.app', ['title' => 'Sign in — ChatApp'])

@section('content')
    <div class="card">
        @if (! empty($otpStep))
            <h1>Enter your OTP</h1>
            <p class="muted">
                We sent a one-time password to <strong>+880{{ $phone }}</strong>. Enter it below to finish signing in.
            </p>

            @if (! empty($status))
                <div class="flash flash-success">{{ $status }}</div>
            @endif

            <form action="{{ route('login.verify') }}" method="POST" novalidate>
                @csrf
                <input type="hidden" name="phone" value="{{ $phone }}">

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
                    <button type="submit" class="btn btn-primary">Verify and sign in</button>
                    <a href="{{ route('login') }}" class="btn btn-secondary">Use a different number</a>
                </div>
            </form>
        @else
            <h1>Sign in to ChatApp</h1>
            <p class="muted">
                Enter your Bangladeshi phone number. We'll send a one-time password to verify and
                activate your subscription.
            </p>

            <form action="{{ route('login.start') }}" method="POST" novalidate>
                @csrf

                <label for="phone">Phone number</label>
                <input
                    id="phone"
                    type="tel"
                    name="phone"
                    pattern="01[3-9][0-9]{8}"
                    placeholder="01812345678"
                    autocomplete="tel"
                    required
                    autofocus
                >
                @error('phone')
                    <div class="field-error">{{ $message }}</div>
                @enderror

                <div class="actions">
                    <button type="submit" class="btn btn-primary">Send OTP</button>
                </div>
            </form>
        @endif
    </div>
@endsection
