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
        Schema::create('whats_app_templates', function (Blueprint $table) {
            $table->id();
            $table->string('business_account_id', 32);
            $table->string('meta_id', 64)->nullable();
            $table->string('name', 128);
            $table->string('language', 32);
            $table->string('category', 32);
            $table->string('status', 64)->default('DRAFT');
            $table->text('reason')->nullable();
            $table->json('components');
            $table->unsignedBigInteger('status_event_time')->nullable();
            $table->timestamps();
            $table->unique(['business_account_id', 'name', 'language']);
            $table->unique(['business_account_id', 'meta_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whats_app_templates');
    }
};
