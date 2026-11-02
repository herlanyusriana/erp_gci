<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penanda periode jam pada hasil produksi.
     *
     * Semua kolom nullable: baris lama adalah data pra-production dan dibiarkan
     * null (tidak di-backfill). Baris dengan `period_start` null berarti "tidak
     * punya periode", bukan periode 00:00.
     */
    public function up(): void
    {
        Schema::table('production_results', function (Blueprint $table) {
            $table->time('period_start')->nullable()->after('shift');
            $table->time('period_end')->nullable()->after('period_start');
            $table->boolean('is_backfilled')->default(false)->after('period_end');
            $table->string('label_status', 20)->default('none')->after('is_backfilled');

            $table->index(['result_date', 'period_start']);
            $table->index('label_status');
        });
    }

    public function down(): void
    {
        Schema::table('production_results', function (Blueprint $table) {
            $table->dropIndex(['result_date', 'period_start']);
            $table->dropIndex(['label_status']);
            $table->dropColumn(['period_start', 'period_end', 'is_backfilled', 'label_status']);
        });
    }
};
