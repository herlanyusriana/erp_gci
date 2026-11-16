<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Status in/out adalah pergerakan (checkout/checkin) yang berubah terus
        // di aplikasi Trolley Scan, bukan atribut master. Menyalinnya ke sini
        // hanya membuat data cepat basi dan memblokir pemindaian yang sah.
        Schema::table('trolleys', function (Blueprint $table) {
            $table->dropIndex(['status', 'kind']);
            $table->dropColumn('status');
        });

        Schema::table('trolleys', function (Blueprint $table) {
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::table('trolleys', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->string('status', 20)->default('in')->after('kind');
            $table->index(['status', 'kind']);
        });
    }
};
