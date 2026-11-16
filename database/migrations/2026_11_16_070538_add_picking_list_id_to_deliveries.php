<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Delivery lahir dari satu picking list; kolom ini menjaga jejak asalnya.
        Schema::table('deliveries', function (Blueprint $table) {
            $table->foreignId('picking_list_id')->nullable()->after('customer_id')
                ->constrained('picking_lists')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('picking_list_id');
        });
    }
};
