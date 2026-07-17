<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks every milestone SMS we've sent so we don't double-send.
     *
     * `user_id` — the subscriber whose chats we're counting.
     * `count`   — the milestone at which we fired the SMS (5, 10, 15…)
     * `sent_at` — when we dispatched the SMS (we keep it as a column
     *             rather than just relying on `created_at` so that
     *             retries after a transport failure are explicit).
     *
     * We rely on Laravel's own unique index to make the milestone
     * step idempotent: one row per (user_id, count). Trying to insert
     * a duplicate raises a QueryException which the caller treats as
     * "already notified, skip".
     */
    public function up(): void
    {
        Schema::create('chat_milestones', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->unsignedInteger('count');
            $table->timestamp('sent_at')->useCurrent();
            $table->string('sms_request_id', 128)->nullable();
            $table->string('sms_status_code', 16)->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'count']);
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_milestones');
    }
};
