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
            $table->string('iqc_status', 20)->default('pending')->after('status'); // pending | passed | rejected
            $table->timestamp('iqc_passed_at')->nullable()->after('iqc_status');
            $table->foreignId('iqc_passed_by')->nullable()->after('iqc_passed_at')->constrained('users')->nullOnDelete();
            $table->string('delivery_note_no')->nullable()->after('invoice_no');

            $table->index('iqc_status');
        });

        // Reset data is_local lama sesuai keputusan Q4=B
        DB::table('incoming_arrivals')->where('is_local', true)->update([
            'is_local' => false,
        ]);
    }

    public function down(): void
    {
        Schema::table('incoming_arrivals', function (Blueprint $table) {
            $table->dropIndex(['iqc_status']);
            $table->dropConstrainedForeignId('iqc_passed_by');
            $table->dropColumn(['iqc_status', 'iqc_passed_at', 'delivery_note_no']);
        });
    }
};
