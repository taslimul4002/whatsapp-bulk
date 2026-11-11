<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('business_account_id', 32);
            $table->string('phone_number_id', 32);
            $table->string('api_version', 10);
            $table->text('access_token');
            $table->text('app_secret');
            $table->text('verify_token');
            $table->timestamps();
        });

        Schema::create('whatsapp_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 64)->unique();
            $table->string('business_account_id', 32);
            $table->string('phone_number_id', 32);
            $table->string('type', 16);
            $table->string('message_id');
            $table->string('status', 32)->nullable();
            $table->string('event_timestamp', 32)->nullable();
            $table->json('payload');
            $table->json('context');
            $table->timestamps();
            $table->index(['phone_number_id', 'message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_events');
        Schema::dropIfExists('whatsapp_accounts');
    }
};
