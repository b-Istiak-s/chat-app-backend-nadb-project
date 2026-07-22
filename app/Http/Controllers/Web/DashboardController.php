<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Auth\StartRequest;
use App\Http\Requests\Web\Auth\VerifyOtpRequest;
use App\Models\User;
use App\Services\BdApps\SubscriptionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticated dashboard — four screens render from this single
 * template based on the user's `subscription_status`:
 *
 *   - unsubscribed/cancelled → subscribe form (covers `cancelled`
 *                              users and `unverified` users who
 *                              abandoned the OTP step)
 *   - awaiting OTP           → verify form (user is `unverified`)
 *   - Payment pending         → auto-refreshing "Payment pending"
 *                              view (user is `pending`; BDApps
 *                              mid-charge, feature access locked)
 *   - Active                  → APK download + unsubscribe (user
 *                              is `registered`; full feature
 *                              access)
 *
 * The four states collapse to two binary gates on the controller
 * side: `$user->isRegistered()` (Active branch) and
 * `$user->isSubscriptionPending()` (Payment pending branch). The
 * `unverified` / `cancelled` cases fall through to the awaiting
 * OTP / Subscribe branches respectively.
 *
 * Every action delegates business work to SubscriptionService; the
 * controller only owns request shaping and the view layer.
 *
 * APK download is gated on `$user->isRegistered()`. The artefact
 * lives under `storage/app/public/downloads/` so it is NOT served
 * as a static asset; the only way to fetch it is through
 * `downloadApk()`.
 */
class DashboardController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptionService,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        $latestSubscription = $user->bdappsSubscriptions()
            ->orderByDesc('id')
            ->first();

        $awaitingOtp = (bool) $request->session()->get('web_auth.awaiting_otp');

        return view('web.dashboard', [
            'user' => $user,
            'subscription' => $latestSubscription,
            'awaitingOtp' => $awaitingOtp,
            'refreshSeconds' => (int) config('bdapps.pending_refresh_seconds', 5),
        ]);
    }

    public function subscribe(StartRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        // Already token-bearing? Don't re-issue OTP — just bounce
        // them back to the dashboard (the appropriate branch
        // renders from there).
        if ($user->isTokenBearing()) {
            return redirect()
                ->route('dashboard.index')
                ->with('status', 'You are already subscribed.');
        }

        try {
            $result = $this->subscriptionService->startSubscription($user);

            $referenceNo = $result['reference_no'] ?? null;

            if (! $referenceNo) {
                // Gateway did not issue an OTP. The user might
                // have been flipped to token-bearing during the
                // start call (e.g. their row reconciled in the
                // background). Re-check and bounce them home.
                if ($user->fresh()->isTokenBearing()) {
                    return redirect()
                        ->route('dashboard.index')
                        ->with('status', 'You are already subscribed.');
                }
            }

            $request->session()->put('web_auth.awaiting_otp', true);

            return redirect()
                ->route('dashboard.index')
                ->with('status', 'OTP sent to your phone. Enter it below to activate.');
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['subscription' => 'Could not start subscription: '.$e->getMessage()]);
        }
    }

    public function verify(VerifyOtpRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();
        $otp = $request->string('otp')->toString();

        try {
            $this->subscriptionService->verifyOtp($user, $otp);
        } catch (\Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'otp' => 'OTP verification failed: '.$e->getMessage(),
            ]);
        }

        $request->session()->forget('web_auth.awaiting_otp');

        // Soft activation: the user is signed in either way; the
        // dashboard's branch on `subscription_status` picks the
        // right view (Active / Payment pending).
        return redirect()
            ->route('dashboard.index')
            ->with('status', 'Subscription activated. You can now download the app.');
    }

    public function refreshStatus(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        try {
            $this->subscriptionService->finalizeActivation($user);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors([
                'subscription' => 'Could not refresh subscription status: '.$e->getMessage(),
            ]);
        }

        $request->session()->forget('web_auth.awaiting_otp');

        if ($user->fresh()->isRegistered()) {
            return redirect()
                ->route('dashboard.index')
                ->with('status', 'Subscription confirmed. You can download the app now.');
        }

        return redirect()
            ->route('dashboard.index')
            ->with('status', 'Still waiting for the gateway to confirm. We will keep trying.');
    }

    public function unsubscribe(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        try {
            $this->subscriptionService->cancelSubscription($user);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors([
                'subscription' => 'Could not cancel subscription: '.$e->getMessage(),
            ]);
        }

        $request->session()->forget('web_auth.awaiting_otp');

        return redirect()
            ->route('dashboard.index')
            ->with('status', 'Subscription cancelled. You can re-subscribe at any time.');
    }

    public function downloadApk(Request $request): BinaryFileResponse
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        if (! $user->isRegistered()) {
            abort(Response::HTTP_FORBIDDEN, 'Active subscription required to download the app.');
        }

        $path = storage_path('app/public/downloads/app-debug.apk');

        if (! is_file($path)) {
            abort(Response::HTTP_NOT_FOUND, 'APK is not currently available.');
        }

        return response()
            ->download($path, 'chat-app.apk', [
                'Content-Type' => 'application/vnd.android.package-archive',
            ]);
    }
}