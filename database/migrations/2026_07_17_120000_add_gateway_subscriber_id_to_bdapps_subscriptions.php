<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add `gateway_subscriber_id` to bdapps_subscriptions. This is the
     * base64 wire identifier (e.g. `tel:ZWRhY2Y5N2Y…`) the Robi gateway
     * returns from /otp/verify, /getStatus and incoming notify webhooks.
     *
     * The existing `subscriber_id` column keeps its current meaning
     * (the normalised `tel:880…` form derived from the phone) so we
     * don't break already-deployed rows or the notify flow.
     *
     * The base64 form is the gateway-canonical subscriber id and is
     * what we now send back to /subscription/send when we have it.
     */
    public function up(): void
    {
        Schema::table('bdapps_subscriptions', function (Blueprint $table) {
            $table->string('gateway_subscriber_id', 191)
                ->nullable()
                ->after('subscriber_id')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('bdapps_subscriptions', function (Blueprint $table) {
            $table->dropIndex(['gateway_subscriber_id']);
            $table->dropColumn('gateway_subscriber_id');
        });
    }
};
