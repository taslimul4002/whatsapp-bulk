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
        Schema::create('contact_contact_group', function (Blueprint $table) {
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_group_id')->constrained()->cascadeOnDelete();
            $table->primary(['contact_id', 'contact_group_id']);
        });
        Schema::create('contact_contact_tag', function (Blueprint $table) {
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['contact_id', 'contact_tag_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_contact_tag');
        Schema::dropIfExists('contact_contact_group');
    }
};
