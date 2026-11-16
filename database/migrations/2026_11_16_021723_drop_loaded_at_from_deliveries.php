<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tahap `loaded` dihapus: label hanya dicetak, bukan gerbang status.
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn('loaded_at');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->timestamp('loaded_at')->nullable()->after('picked_at');
        });
    }
};
