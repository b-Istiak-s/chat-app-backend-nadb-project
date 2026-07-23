<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename `cancelled` → `unregistered` on both the row and the user.
 *
 * Rationale: `unregistered` is the gateway-canonical name (the
 * `UNREGISTERED` reply from BDApps), and that's the actual
 * lifecycle event for the user — they (or the gateway) cancelled.
 * The local value `cancelled` was a paraphrased alias; the rename
 * keeps the local column and the gateway mirror column aligned
 * semantically. Same set of states, one name. Down-mappings are
 * unchanged.
 *
 *   users.subscription_status:    'unverified' | 'pending' | 'registered' | 'unregistered'
 *   bdapps_subscriptions.status:  'unverified' | 'pending' | 'registered' | 'unregistered'
 *
 * Existing rows are rewritten in-place; no row is created or
 * destroyed. Default values are unchanged — fresh inserts still
 * default to `unverified`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            UPDATE users
            SET subscription_status = 'unregistered'
            WHERE subscription_status = 'cancelled'
        ");

        DB::statement("
            UPDATE bdapps_subscriptions
            SET status = 'unregistered'
            WHERE status = 'cancelled'
        ");
    }

    public function down(): void
    {
        DB::statement("
            UPDATE users
            SET subscription_status = 'cancelled'
            WHERE subscription_status = 'unregistered'
        ");

        DB::statement("
            UPDATE bdapps_subscriptions
            SET status = 'cancelled'
            WHERE status = 'unregistered'
        ");
    }
};
