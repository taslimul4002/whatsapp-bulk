<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary();
        });

        DB::table('currencies')->insert(['code' => 'SAR']);

        Schema::table('users', function (Blueprint $table) {
            $table->char('currency_code', 3)->nullable();
            $table->foreign('currency_code')->references('code')->on('currencies')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['currency_code']);
            $table->dropColumn('currency_code');
        });

        Schema::dropIfExists('currencies');
    }
};
