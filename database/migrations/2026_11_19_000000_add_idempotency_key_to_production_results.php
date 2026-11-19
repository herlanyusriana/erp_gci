<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_results', function (Blueprint $table) {
            // NULL untuk laporan dari web (belum mengirim key); baris tanpa key
            // tetap boleh banyak karena Postgres mengabaikan NULL pada unique.
            $table->string('idempotency_key', 80)->nullable()->unique()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('production_results', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
