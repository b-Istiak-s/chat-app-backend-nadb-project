<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refactor subscription state names.
 *
 * Old model (mixed naming):
 *   - users.subscription_status: 'subscribed' | 'unsubscribed'
 *   - bdapps_subscriptions.status: 'pending' | 'registered' | 'unregistered'
 *
 * New model (unified three-state, prior to the next migration):
 *   - users.subscription_status: 'unverified' | 'pending' | 'cancelled'
 *   - bdapps_subscriptions.status: 'unverified' | 'pending' | 'cancelled'
 *
 * Note: a follow-up migration `2026_07_23_120000_add_registered_state.php`
 * adds `registered` as a fourth state on top of this three-state base.
 * The `down()` here therefore maps `registered` back to `pending`
 * (same as the prior three-state treatment) so the four-state
 * migration's `down()` is a no-op on top of this one.
 *
 * Mapping rules:
 *   - 'subscribed' → 'pending' (token-bearing; OTP verified)
 *   - 'unsubscribed' → 'unverified' (must re-OTP)
 *   - 'registered' (row) → 'pending' (BDApps confirmed; row stays
 *     pending because user still has access; mirror column carries
 *     the literal "REGISTERED"). The follow-up migration lifts
 *     these to `registered`.
 *   - 'pending' (row) → split:
 *       - rows that were OTP-in-flight (have reference_no, no mirror
 *         yet) → 'unverified' (we never finished verifying them)
 *       - rows that were verified by us → 'pending' (token was
 *         already issued; gateway is mid-charge)
 *   - 'unregistered' (row) → 'cancelled'
 *
 * After the data rewrite, change column defaults to 'unverified' so
 * fresh inserts follow the new model.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── users.subscription_status ───────────────────────────────────
        DB::statement("
            UPDATE users
            SET subscription_status = 'pending'
            WHERE subscription_status = 'subscribed'
        ");

        DB::statement("
            UPDATE users
            SET subscription_status = 'unverified'
            WHERE subscription_status = 'unsubscribed'
        ");

        // Anything unexpected stays as-is (will surface as a bug to
        // investigate rather than silently mapping to a wrong value).

        // ─── bdapps_subscriptions.status ─────────────────────────────────
        DB::statement("
            UPDATE bdapps_subscriptions
            SET status = 'pending'
            WHERE status = 'registered'
        ");

        // Old 'pending' rows split into two cases:
        //   1. OTP was never finished (reference_no is set, gateway
        //      never replied) — these were abandoned mid-flow. They
        //      belong at 'unverified' so the next /auth/start will
        //      request a fresh OTP.
        //   2. OTP was verified and the gateway was mid-charge —
        //      they belong at 'pending'.
        //
        // Heuristic: rows with a reference_no AND no gateway mirror
        // (bdapps_subscription_status IS NULL) AND no gateway_subscriber_id
        // are abandoned-in-flight. Everything else is "OTP verified,
        // gateway mid-charge".
        DB::statement("
            UPDATE bdapps_subscriptions
            SET status = 'unverified'
            WHERE status = 'pending'
              AND reference_no IS NOT NULL
              AND (bdapps_subscription_status IS NULL OR bdapps_subscription_status = '')
              AND (gateway_subscriber_id IS NULL OR gateway_subscriber_id = '')
        ");

        DB::statement("
            UPDATE bdapps_subscriptions
            SET status = 'cancelled'
            WHERE status = 'unregistered'
        ");

        // Any leftover 'pending' rows that didn't match the heuristic
        // above stay 'pending' (they were the verified-and-mid-charge
        // cases — the natural 'pending' set in the new model).

        // ─── Defaults ────────────────────────────────────────────────────
        Schema::table('users', function ($table) {
            $table->string('subscription_status', 32)->default('unverified')->change();
        });

        Schema::table('bdapps_subscriptions', function ($table) {
            $table->string('status', 32)->default('unverified')->change();
        });
    }

    public function down(): void
    {
        // Reverse the mapping. The follow-up `registered` migration
        // adds a fourth state on top of this one; its `down()` is
        // expected to run BEFORE this one in any rollback sequence
        // (i.e. `registered → pending` is its responsibility).
        // Either way, we treat `registered` like `pending` here as a
        // belt-and-braces fallback so the rollback is correct even if
        // the four-state migration is still applied.
        DB::statement("
            UPDATE users
            SET subscription_status = 'subscribed'
            WHERE subscription_status IN ('pending', 'registered')
        ");

        DB::statement("
            UPDATE users
            SET subscription_status = 'unsubscribed'
            WHERE subscription_status IN ('unverified', 'cancelled')
        ");

        DB::statement("
            UPDATE bdapps_subscriptions
            SET status = 'registered'
            WHERE status IN ('pending', 'registered')
              AND bdapps_subscription_status = 'REGISTERED'
        ");

        DB::statement("
            UPDATE bdapps_subscriptions
            SET status = 'pending'
            WHERE status IN ('pending', 'registered')
        ");

        DB::statement("
            UPDATE bdapps_subscriptions
            SET status = 'unregistered'
            WHERE status = 'cancelled'
        ");

        DB::statement("
            UPDATE bdapps_subscriptions
            SET status = 'pending'
            WHERE status = 'unverified'
        ");

        Schema::table('users', function ($table) {
            $table->string('subscription_status', 32)->default('unsubscribed')->change();
        });

        Schema::table('bdapps_subscriptions', function ($table) {
            $table->string('status', 32)->default('pending')->change();
        });
    }
};
