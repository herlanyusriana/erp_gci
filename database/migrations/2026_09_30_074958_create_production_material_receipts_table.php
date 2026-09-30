<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penerimaan material oleh production di lantai.
     *
     * Setelah warehouse issue-out, operator produksi scan tag material + QR mesin.
     * Kolom (material_issue_item_id, machine_id) unik dalam satu baris.
     */
    public function up(): void
    {
        Schema::create('production_material_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('material_issue_item_id');
            $table->string('tag', 255)->nullable();
            $table->unsignedBigInteger('part_id')->nullable();
            $table->unsignedBigInteger('machine_id');
            $table->unsignedBigInteger('received_by')->nullable();
            $table->dateTime('received_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('machine_id');
            $table->index('received_at');
            $table->unique(['material_issue_item_id', 'machine_id'], 'uq_receipt_material_machine');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_material_receipts');
    }
};
