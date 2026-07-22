<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add `registered` as a fourth first-class state on top of the
 * three-state model introduced by
 * `2026_07_22_120000_refactor_subscription_states.php`.
 *
 * Why a separate migration: the three-state model collapsed the
 * gateway's `REGISTERED` reply into a mirror-column read
 * (`bdapps_subscription_status = 'REGISTERED'`) with the row
 * staying at `pending`. The user-facing problem with that is
 * "subscribed" stops being a queryable property — the only way to
 * ask "does this user have full access?" is to join through the
 * mirror column. That's brittle and surprising.
 *
 * This migration lifts any `pending` row whose mirror already says
 * `REGISTERED` to the new `registered` state. After this:
 *
 *   - users.subscription_status:    'unverified' | 'pending' | 'registered' | 'cancelled'
 *   - bdapps_subscriptions.status:  'unverified' | 'pending' | 'registered' | 'cancelled'
 *
 * The mirror column `bdapps_subscription_status` is **kept** for
 * forensics / display — it still records the literal BDApps reply.
 * It is no longer the source of truth for "can the user access
 * features?".
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── Rows ────────────────────────────────────────────────────────
        // Any mid-charge `pending` row whose gateway mirror already
        // says `REGISTERED` is lifted to `registered`. This is the
        // backfill for users who paid up between when we shipped
        // the three-state refactor and this migration.
        DB::statement("
            UPDATE bdapps_subscriptions
            SET status = 'registered'
            WHERE status = 'pending'
              AND bdapps_subscription_status = 'REGISTERED'
        ");

        // ─── Users ───────────────────────────────────────────────────────
        // Mirror the row flip: any user whose latest row is now
        // `registered` gets `subscription_status = 'registered'`.
        // MySQL forbids `LIMIT` inside a JOIN ... ON subquery
        // without a derived table on older versions, so we use a
        // derived table to pick the user's most recent row.
        DB::statement("
            UPDATE users u
            JOIN (
                SELECT b.user_id, b.status
                FROM bdapps_subscriptions b
                JOIN (
                    SELECT user_id, MAX(id) AS max_id
                    FROM bdapps_subscriptions
                    GROUP BY user_id
                ) latest ON latest.user_id = b.user_id AND latest.max_id = b.id
            ) m ON m.user_id = u.id
            SET u.subscription_status = 'registered'
            WHERE u.subscription_status = 'pending'
              AND m.status = 'registered'
        ");
    }

    public function down(): void
    {
        // Inverse: `registered` → `pending` on both row and user.
        // The mirror column is unchanged — it still says
        // `REGISTERED` — so the legacy model can recover the
        // distinction by reading the mirror.
        DB::statement("
            UPDATE users
            SET subscription_status = 'pending'
            WHERE subscription_status = 'registered'
        ");

        DB::statement("
            UPDATE bdapps_subscriptions
            SET status = 'pending'
            WHERE status = 'registered'
        ");
    }
};
