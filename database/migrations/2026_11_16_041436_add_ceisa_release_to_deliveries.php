<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gate sebelum No. PEN/AJU diisi: data pengiriman harus sudah dirilis
        // ke CEISA, karena nomor PEN/AJU justru terbit dari sana.
        Schema::table('deliveries', function (Blueprint $table) {
            $table->timestamp('ceisa_released_at')->nullable()->after('aju_no');
            $table->unsignedBigInteger('ceisa_released_by')->nullable()->after('ceisa_released_at');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn(['ceisa_released_at', 'ceisa_released_by']);
        });
    }
};
