<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_results', function (Blueprint $table) {
            $table->decimal('label_carried_over_qty', 12, 4)->default(0)->after('label_status');
            $table->foreignId('label_carry_over_source_id')
                ->nullable()
                ->after('label_carried_over_qty')
                ->constrained('production_results')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('production_results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('label_carry_over_source_id');
            $table->dropColumn(['label_carried_over_qty']);
        });
    }
};
