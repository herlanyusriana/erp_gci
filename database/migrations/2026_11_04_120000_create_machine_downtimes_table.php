<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_downtimes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained('machines')->cascadeOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->foreignId('part_id')->nullable()->constrained('parts')->nullOnDelete();
            $table->date('downtime_date');
            $table->string('shift', 10);
            $table->string('category', 20); // qdc | downtime
            $table->string('reason', 50); // qdc | die_trouble | machine_breakdown | waiting_material | waiting_qc | other
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('duration_minutes');
            $table->text('notes')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['machine_id', 'downtime_date', 'shift']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_downtimes');
    }
};
