<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dokumen pengeluaran material ke produksi ("issue out").
        Schema::create('material_issues', function (Blueprint $table) {
            $table->id();
            $table->string('issue_no')->unique();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->date('issue_date');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('received_by')->nullable();
            $table->string('status')->default('posted'); // posted | cancelled
            $table->string('idempotency_key')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index('work_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_issues');
    }
};
