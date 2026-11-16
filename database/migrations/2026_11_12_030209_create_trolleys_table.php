<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mirror master troli dari aplikasi Trolley Scan. Label & QR sudah
        // dicetak di sana, jadi ERP hanya menyimpan salinan untuk memvalidasi
        // hasil pindai dan menampilkan pilihan troli.
        Schema::create('trolleys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('external_id')->nullable()->index();
            $table->string('code', 60)->unique();
            $table->string('type', 20)->default('internal');
            $table->string('kind', 30)->nullable();
            $table->string('status', 20)->default('in');
            $table->unsignedInteger('capacity')->nullable();
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trolleys');
    }
};
