<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_stocks', function (Blueprint $table) {
            $table->timestamp('received_at')->nullable()->after('qty');
            $table->unsignedBigInteger('receive_id')->nullable()->after('received_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('part_stocks', function (Blueprint $table) {
            $table->dropColumn(['receive_id', 'received_at']);
        });
    }
};