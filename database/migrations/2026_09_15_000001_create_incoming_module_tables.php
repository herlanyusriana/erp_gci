<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ──────────────────────────────────────────────
        // VENDOR PARTS (part-no per supplier, maps to master parts)
        // ──────────────────────────────────────────────
        Schema::create('vendor_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->nullable()->constrained('parts')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('vendor_part_no');
            $table->string('vendor_part_name')->nullable();
            $table->decimal('price', 20, 4)->nullable();
            $table->string('currency', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['supplier_id', 'vendor_part_no']);
            $table->index(['part_id']);
            $table->index(['vendor_part_no']);
        });

        // ──────────────────────────────────────────────
        // PURCHASE ORDERS + ITEMS
        // ──────────────────────────────────────────────
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_no')->unique();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('status')->default('draft'); // draft | confirmed | received | cancelled
            $table->date('po_date')->nullable();
            $table->date('expected_date')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['supplier_id']);
            $table->index(['status']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('vendor_part_id')->nullable()->constrained('vendor_parts')->nullOnDelete();
            $table->foreignId('part_id')->nullable()->constrained('parts')->nullOnDelete();
            $table->decimal('qty', 20, 4)->default(0);
            $table->string('unit', 20)->nullable();
            $table->decimal('price', 20, 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['purchase_order_id']);
            $table->index(['vendor_part_id']);
        });

        // ──────────────────────────────────────────────
        // INCOMING ARRIVALS
        // ──────────────────────────────────────────────
        Schema::create('incoming_arrivals', function (Blueprint $table) {
            $table->id();
            $table->string('arrival_no')->unique();
            $table->string('transaction_no')->nullable()->unique(); // SOxxxxx
            $table->string('invoice_no')->nullable();
            $table->date('invoice_date')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();

            $table->string('vessel')->nullable();
            $table->date('etd')->nullable();
            $table->date('eta')->nullable();
            $table->date('eta_gci')->nullable();

            $table->string('bill_of_lading')->nullable();
            $table->string('pen_no')->nullable();
            $table->date('pen_date')->nullable();
            $table->string('aju_no')->nullable();

            $table->string('bill_of_lading_file')->nullable();
            $table->string('delivery_note_file')->nullable();
            $table->string('invoice_file')->nullable();
            $table->string('packing_list_file')->nullable();

            $table->string('price_term')->nullable(); // FOB, CIF
            $table->string('hs_code')->nullable();
            $table->string('port_of_loading')->nullable();
            $table->string('country')->nullable();
            $table->string('currency', 10)->nullable();

            $table->text('notes')->nullable();
            $table->string('status')->default('pending'); // pending | completed | cancelled
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['arrival_no']);
            $table->index(['invoice_no']);
            $table->index(['supplier_id']);
            $table->index(['status']);
            $table->index(['eta']);
        });

        // ──────────────────────────────────────────────
        // INCOMING ARRIVAL ITEMS
        // ──────────────────────────────────────────────
        Schema::create('incoming_arrival_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('arrival_id')->constrained('incoming_arrivals')->cascadeOnDelete();
            $table->foreignId('vendor_part_id')->nullable()->constrained('vendor_parts')->nullOnDelete();
            $table->foreignId('part_id')->nullable()->constrained('parts')->nullOnDelete();

            $table->string('material_group')->nullable();
            $table->string('size')->nullable();

            $table->decimal('qty_goods', 20, 4)->default(0);
            $table->string('unit_goods', 20)->nullable();
            $table->decimal('qty_bundle', 20, 4)->nullable();
            $table->string('unit_bundle', 20)->nullable();

            $table->decimal('weight_nett', 20, 4)->nullable();
            $table->string('unit_weight', 20)->nullable();
            $table->decimal('weight_gross', 20, 4)->nullable();

            $table->decimal('price', 20, 4)->nullable();
            $table->decimal('total_price', 20, 4)->nullable();

            $table->boolean('is_foc')->default(false);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->index(['arrival_id']);
            $table->index(['vendor_part_id']);
            $table->index(['part_id']);
        });

        // ──────────────────────────────────────────────
        // INCOMING ARRIVAL CONTAINERS + INSPECTIONS
        // ──────────────────────────────────────────────
        Schema::create('incoming_arrival_containers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('arrival_id')->constrained('incoming_arrivals')->cascadeOnDelete();
            $table->string('container_no');
            $table->string('seal_code')->nullable();
            $table->string('size')->nullable(); // 20ft, 40ft
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['arrival_id', 'container_no']);
            $table->index(['container_no']);
        });

        Schema::create('incoming_arrival_container_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('arrival_container_id')->unique()->constrained('incoming_arrival_containers')->cascadeOnDelete();
            $table->string('status')->default('ok'); // ok | damage
            $table->string('seal_condition')->nullable(); // ok | broken | missing
            $table->string('container_condition')->nullable(); // ok | dented | hole
            $table->string('photo_front')->nullable();
            $table->string('photo_back')->nullable();
            $table->string('photo_left')->nullable();
            $table->string('photo_right')->nullable();
            $table->string('photo_inside')->nullable();
            $table->string('photo_seal')->nullable();
            $table->string('driver_name')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('inspected_by')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // ──────────────────────────────────────────────
        // INCOMING RECEIVES (FIFO tag)
        // ──────────────────────────────────────────────
        Schema::create('incoming_receives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('arrival_item_id')->constrained('incoming_arrival_items')->cascadeOnDelete();
            $table->foreignId('part_id')->nullable()->constrained('parts')->nullOnDelete();

            $table->string('tag')->nullable(); // batch / lot number
            $table->decimal('qty', 20, 4)->default(0);
            $table->string('qty_unit', 20)->nullable();

            $table->decimal('bundle_qty', 20, 4)->nullable();
            $table->string('bundle_unit', 20)->nullable();

            $table->decimal('weight', 20, 4)->nullable();
            $table->decimal('net_weight', 20, 4)->nullable();
            $table->decimal('gross_weight', 20, 4)->nullable();
            $table->decimal('weight_kgm', 20, 4)->nullable();

            $table->string('location_code')->nullable();
            $table->string('qc_status')->nullable(); // pass | reject | pending

            $table->string('invoice_no')->nullable();
            $table->string('delivery_note_no')->nullable();
            $table->string('truck_no')->nullable();
            $table->string('jo_po_number')->nullable();

            $table->dateTime('ata_date')->nullable();
            $table->timestamp('qc_audited_at')->nullable();
            $table->unsignedBigInteger('qc_audited_by')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->index(['arrival_item_id']);
            $table->index(['part_id']);
            $table->index(['location_code']);
            $table->index(['tag']);
            $table->index(['ata_date']);
        });

        // ──────────────────────────────────────────────
        // PART STOCKS (sederhana: per part + tag, tanpa rack/warehouse)
        // ──────────────────────────────────────────────
        Schema::create('part_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->constrained('parts')->cascadeOnDelete();
            $table->string('tag')->nullable(); // FIFO tag
            $table->decimal('qty', 20, 4)->default(0);
            $table->string('qty_unit', 20)->nullable();
            $table->decimal('price', 20, 4)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['part_id']);
            $table->index(['tag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('part_stocks');
        Schema::dropIfExists('incoming_receives');
        Schema::dropIfExists('incoming_arrival_container_inspections');
        Schema::dropIfExists('incoming_arrival_containers');
        Schema::dropIfExists('incoming_arrival_items');
        Schema::dropIfExists('incoming_arrivals');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('vendor_parts');
    }
};