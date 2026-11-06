<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incoming_arrival_items', function (Blueprint $table) {
            $table->string('iqc_status', 20)->default('pending')->after('is_foc'); // pending | passed | rejected
            $table->timestamp('iqc_passed_at')->nullable()->after('iqc_status');
            $table->foreignId('iqc_passed_by')->nullable()->after('iqc_passed_at')->constrained('users')->nullOnDelete();

            $table->index('iqc_status');
        });
    }

    public function down(): void
    {
        Schema::table('incoming_arrival_items', function (Blueprint $table) {
            $table->dropIndex(['iqc_status']);
            $table->dropConstrainedForeignId('iqc_passed_by');
            $table->dropColumn(['iqc_status', 'iqc_passed_at']);
        });
    }
};
