<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incoming_arrivals', function (Blueprint $table) {
            $table->string('po_no')->nullable()->after('invoice_no');
        });

        // Lokal lama menyimpan No. PO di invoice_no → pindahkan ke po_no.
        DB::table('incoming_arrivals')
            ->where('is_local', true)
            ->whereNotNull('invoice_no')
            ->update([
                'po_no' => DB::raw('invoice_no'),
                'invoice_no' => null,
            ]);

        Schema::table('incoming_arrivals', function (Blueprint $table) {
            $table->unique('po_no');
        });
    }

    public function down(): void
    {
        Schema::table('incoming_arrivals', function (Blueprint $table) {
            $table->dropUnique(['po_no']);
            $table->dropColumn('po_no');
        });
    }
};
