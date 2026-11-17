<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Identitas customer untuk dokumen kepabeanan: nama pendek dipakai pada
        // layout invoice/packing list, NPWP & fasilitas BC dipakai saat data
        // dikirim ke CEISA.
        Schema::table('customers', function (Blueprint $table) {
            $table->string('short_name', 100)->nullable()->after('customer_name');
            $table->string('contact_person', 150)->nullable()->after('phone');
            $table->string('npwp', 32)->nullable()->after('email');
            $table->string('bc_facility', 20)->nullable()->after('npwp');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['short_name', 'contact_person', 'npwp', 'bc_facility']);
        });
    }
};
