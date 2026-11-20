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
            $table->string('arrival_type', 20)->default('import')->after('is_local');
            $table->foreignId('subcontractor_id')->nullable()->after('supplier_id')->constrained('subcontractors')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->after('subcontractor_id')->constrained('customers')->nullOnDelete();
            $table->string('contract_no', 100)->nullable()->after('delivery_note_no');
            $table->string('reference_no', 100)->nullable()->after('contract_no');

            $table->index('arrival_type');
            $table->index('subcontractor_id');
            $table->index('customer_id');
            $table->index('contract_no');
        });

        DB::table('incoming_arrivals')
            ->where('is_local', true)
            ->update(['arrival_type' => 'local']);
    }

    public function down(): void
    {
        Schema::table('incoming_arrivals', function (Blueprint $table) {
            $table->dropForeign(['subcontractor_id']);
            $table->dropForeign(['customer_id']);
            $table->dropIndex(['arrival_type']);
            $table->dropIndex(['subcontractor_id']);
            $table->dropIndex(['customer_id']);
            $table->dropIndex(['contract_no']);
            $table->dropColumn(['arrival_type', 'subcontractor_id', 'customer_id', 'contract_no', 'reference_no']);
        });
    }
};
