<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('request_id')->unique();
            $table->string('name');
            $table->string('business_account_id', 32);
            $table->string('phone_number_id', 32);
            $table->string('template_name', 128);
            $table->string('template_language', 32);
            $table->timestamp('scheduled_at');
            $table->timestamps();
        });
        Schema::create('broadcast_credit_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('billing_purchases')->restrictOnDelete();
            $table->unsignedBigInteger('source_recipient_id');
            $table->unsignedInteger('source_cycle');
            $table->integer('balance');
            $table->timestamps();
            $table->unique(['source_recipient_id', 'source_cycle']);
            $table->index('purchase_id');
        });
        Schema::create('broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_id')->constrained('billing_purchases')->restrictOnDelete();
            $table->foreignId('credit_grant_id')->nullable()->constrained('broadcast_credit_grants')->restrictOnDelete();
            $table->string('phone', 16);
            $table->json('components');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedInteger('cycle')->default(1);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->string('message_id')->nullable()->unique();
            $table->string('error')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['broadcast_id', 'phone']);
            $table->index(['status', 'next_attempt_at']);
            $table->index('attempted_at');
        });
        Schema::create('broadcast_send_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('phone_number_id', 32);
            $table->timestamp('created_at');
            $table->index(['phone_number_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_send_attempts');
        Schema::dropIfExists('broadcast_recipients');
        Schema::dropIfExists('broadcast_credit_grants');
        Schema::dropIfExists('broadcasts');
    }
};
