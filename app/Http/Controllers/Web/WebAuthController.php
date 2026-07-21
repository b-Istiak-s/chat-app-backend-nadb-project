<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Auth\StartRequest;
use App\Http\Requests\Web\Auth\VerifyOtpRequest;
use App\Models\BdappsSubscription;
use App\Models\User;
use App\Services\BdApps\SubscriptionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Web (cookie-session) counterpart of the API AuthController.
 *
 * Single-user-type rule still applies: there is no role check, no
 * email, no password. The same BDApps subscription lifecycle is
 * used; we only swap Sanctum bearer tokens for a Laravel `web`
 * guard session. (See lessons.md — web session and Sanctum token are
 * independent surfaces; issuing one does not mint the other.)
 *
 * Flow:
 *   - GET  /login          → phone form (or OTP form when pending)
 *   - POST /login/start    → find-or-create user, kick OTP, render OTP step
 *   - POST /login/verify   → verify OTP, sign in via the web guard,
 *                             redirect to /dashboard
 *   - POST /logout         → end session
 */
class WebAuthController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptionService,
    ) {}

    public function showLogin(Request $request): View|RedirectResponse
    {
        if ($request->session()->has('web_auth.phone')) {
            return $this->renderOtpStep($request->session()->get('web_auth.phone'));
        }

        if (Auth::guard('web')->check()) {
            return redirect()->route('dashboard.index');
        }

        return view('web.auth.login');
    }

    public function start(StartRequest $request): View|RedirectResponse
    {
        $phone = $request->string('phone')->toString();

        // If a session already exists, send them on.
        if (Auth::guard('web')->check()) {
            return redirect()->route('dashboard.index');
        }

        try {
            $user = User::firstOrCreate(
                ['phone' => $phone],
                ['subscription_status' => 'unsubscribed'],
            );

            // Subscribed or pending → trust them; sign them in directly.
            if ($this->userCanSkipOtp($user)) {
                Auth::guard('web')->login($user);
                $request->session()->forget('web_auth.phone');
                $request->session()->regenerate();

                return redirect()
                    ->route('dashboard.index')
                    ->with('status', 'Welcome back.');
            }

            $result = $this->subscriptionService->startSubscription($user);

            $referenceNo = $result['reference_no'] ?? null;

            if (! $referenceNo) {
                // Gateway did not issue an OTP but the user is not yet
                // subscribed — most likely they already hold an active
                // subscription row. Re-check and sign them in.
                if ($user->fresh()->isSubscribed()) {
                    Auth::guard('web')->login($user);
                    $request->session()->regenerate();

                    return redirect()
                        ->route('dashboard.index')
                        ->with('status', 'Welcome back.');
                }
            }

            $request->session()->put('web_auth.phone', $phone);

            return $this->renderOtpStep($phone, 'OTP sent to your phone.');
        } catch (\Throwable $e) {
            report($e);

            return back()
                ->withInput()
                ->withErrors(['phone' => 'Could not start subscription: '.$e->getMessage()]);
        }
    }

    public function verify(VerifyOtpRequest $request): RedirectResponse
    {
        $phone = $request->string('phone')->toString();
        $otp = $request->string('otp')->toString();

        $sessionPhone = $request->session()->get('web_auth.phone');
        if ($sessionPhone !== $phone) {
            throw ValidationException::withMessages([
                'phone' => 'Session expired. Please request a new OTP.',
            ]);
        }

        $user = User::where('phone', $phone)->first();
        if (! $user) {
            throw ValidationException::withMessages([
                'phone' => 'No account found for this phone. Please request a new OTP.',
            ]);
        }

        try {
            $this->subscriptionService->verifyOtp($user, $otp);
        } catch (\Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'otp' => 'OTP verification failed: '.$e->getMessage(),
            ]);
        }

        // Soft activation: any non-empty gateway response flips the
        // user to `subscribed`, so we always sign in. The row's
        // status (`registered` vs `pending`) drives the dashboard's
        // "Payment not confirmed" view — the user is signed in either
        // way.
        Auth::guard('web')->login($user);
        $request->session()->forget('web_auth.phone');
        $request->session()->regenerate();

        return redirect()
            ->route('dashboard.index')
            ->with('status', 'Subscription activated.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('landing');
    }

    private function renderOtpStep(string $phone, ?string $status = null): View
    {
        return view('web.auth.login', [
            'otpStep' => true,
            'phone' => $phone,
            'status' => $status,
        ]);
    }

    /**
     * Mirror of API AuthController::userCanSkipOtp(). The auth surface
     * stays open for users we already trust: `subscribed`, or anyone
     * with a `pending` row whose activation is in flight. The
     * dashboard's "Payment not confirmed" view (driven by the row's
     * `status`) gates the *service* surface; this gate decides only
     * whether the OTP step can be skipped on `start`.
     */
    private function userCanSkipOtp(User $user): bool
    {
        if ($user->isSubscribed()) {
            return true;
        }

        return $user->bdappsSubscriptions()
            ->where('status', BdappsSubscription::STATUS_PENDING)
            ->exists();
    }
}
