<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incoming_receives', function (Blueprint $table) {
            $table->string('idempotency_key', 80)->nullable()->after('qc_status');
            $table->index(['arrival_item_id', 'idempotency_key'], 'incoming_receives_item_idempotency_idx');
        });
    }

    public function down(): void
    {
        Schema::table('incoming_receives', function (Blueprint $table) {
            $table->dropIndex('incoming_receives_item_idempotency_idx');
            $table->dropColumn('idempotency_key');
        });
    }
};
