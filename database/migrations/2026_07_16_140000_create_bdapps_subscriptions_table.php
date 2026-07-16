<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bdapps_subscriptions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->string('phone', 32);
            $table->string('subscriber_id', 64)->nullable()->index();
            $table->string('status', 32)->default('pending');
            $table->string('bdapps_subscription_status', 64)->nullable();
            $table->string('reference_no', 128)->nullable();
            $table->string('application_id', 64)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('last_notified_at')->nullable();

            $table->json('raw_register_response')->nullable();
            $table->json('raw_otp_request_response')->nullable();
            $table->json('raw_status_response')->nullable();

            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bdapps_subscriptions');
    }
};