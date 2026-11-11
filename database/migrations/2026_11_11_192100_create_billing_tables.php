<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('credits');
            $table->unsignedInteger('expiry_days')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('billing_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('billing_plans')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('plan_name', 120);
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('credits');
            $table->unsignedInteger('expiry_days')->nullable();
            $table->string('status', 30)->default('creating');
            $table->uuid('request_id')->unique();
            $table->uuid('capture_request_id')->unique();
            $table->uuid('refund_request_id')->nullable()->unique();
            $table->string('paypal_order_id')->nullable()->unique();
            $table->string('paypal_capture_id')->nullable()->unique();
            $table->string('paypal_refund_id')->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('review_required')->default(false);
            $table->timestamps();
            $table->index(['status', 'expires_at']);
        });
        Schema::create('billing_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('billing_purchases')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30);
            $table->string('provider_id')->unique();
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3)->default('USD');
            $table->timestamp('created_at');
        });
        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->unique()->constrained('billing_purchases')->restrictOnDelete();
            $table->string('number')->unique();
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3)->default('USD');
            $table->timestamp('issued_at');
        });
        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_id')->constrained('billing_purchases')->restrictOnDelete();
            $table->string('type', 30);
            $table->string('idempotency_key')->nullable()->unique();
            $table->integer('credits');
            $table->timestamp('created_at');
        });
        Schema::create('paypal_webhook_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('type');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paypal_webhook_events');
        Schema::dropIfExists('credit_ledger');
        Schema::dropIfExists('billing_invoices');
        Schema::dropIfExists('billing_transactions');
        Schema::dropIfExists('billing_purchases');
        Schema::dropIfExists('billing_plans');
    }
};
