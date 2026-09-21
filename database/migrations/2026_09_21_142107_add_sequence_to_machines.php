<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            // Urutan tampil mesin di papan Production Plan (kecil = lebih atas).
            $table->unsignedInteger('sequence')->nullable()->after('machine_name');
            $table->index('sequence');
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropIndex(['sequence']);
            $table->dropColumn('sequence');
        });
    }
};
