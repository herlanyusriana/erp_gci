<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm;');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_parts_part_number_trgm ON parts USING gin (part_number gin_trgm_ops);');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_parts_part_name_trgm ON parts USING gin (part_name gin_trgm_ops);');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_suppliers_name_trgm ON suppliers USING gin (supplier_name gin_trgm_ops);');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_purchase_orders_no_trgm ON purchase_orders USING gin (po_no gin_trgm_ops);');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_parts_part_number_trgm;');
            DB::statement('DROP INDEX IF EXISTS idx_parts_part_name_trgm;');
            DB::statement('DROP INDEX IF EXISTS idx_suppliers_name_trgm;');
            DB::statement('DROP INDEX IF EXISTS idx_purchase_orders_no_trgm;');
        }
    }
};
