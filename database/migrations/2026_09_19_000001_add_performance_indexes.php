<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit performa database (2026-09-19).
 *
 * P1. Hapus index duplikat pada incoming_arrivals.arrival_no (sudah ada UNIQUE).
 * P2. Tambah index untuk kolom FK yang belum terindeks (cascade + join).
 * P3. Index FIFO part_stocks: urutan received_at ASC NULLS LAST, hanya baris aktif.
 * P4. Partial index soft-delete untuk tabel transaksi yang paling sering dibaca.
 *
 * Catatan: CREATE/DROP INDEX CONCURRENTLY tidak boleh berjalan di dalam
 * transaksi, sehingga $withinTransaction dimatikan.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var list<string> */
    private array $createdIndexes = [];

    public function up(): void
    {
        // ── P1: index duplikat ────────────────────────────────────────────
        $this->exec('DROP INDEX CONCURRENTLY IF EXISTS incoming_arrivals_arrival_no_index');

        // ── P2: index FK yang belum ada ───────────────────────────────────
        $foreignKeys = [
            'parts_uom_id_index' => 'parts (uom_id)',
            'part_substitutes_substitute_part_id_index' => 'part_substitutes (substitute_part_id)',
            'part_substitutes_supplier_id_index' => 'part_substitutes (supplier_id)',
            'bom_items_child_part_id_index' => 'bom_items (child_part_id)',
            'bom_items_parent_part_id_index' => 'bom_items (parent_part_id)',
            'bom_items_machine_id_index' => 'bom_items (machine_id)',
            'bom_items_process_id_index' => 'bom_items (process_id)',
            'work_order_items_child_part_id_index' => 'work_order_items (child_part_id)',
            'work_order_items_parent_part_id_index' => 'work_order_items (parent_part_id)',
            'work_order_items_machine_id_index' => 'work_order_items (machine_id)',
            'work_order_items_process_id_index' => 'work_order_items (process_id)',
            'work_order_consumptions_work_order_item_id_index' => 'work_order_consumptions (work_order_item_id)',
            'incoming_arrivals_purchase_order_id_index' => 'incoming_arrivals (purchase_order_id)',
            'incoming_arrivals_trucking_company_id_index' => 'incoming_arrivals (trucking_company_id)',
            'purchase_order_items_part_id_index' => 'purchase_order_items (part_id)',
            'machine_process_process_id_index' => 'machine_process (process_id)',
            'production_plan_items_fg_part_id_index' => 'production_plan_items (fg_part_id)',
            'production_plan_items_wip_part_id_index' => 'production_plan_items (wip_part_id)',
            'production_plan_items_machine_id_index' => 'production_plan_items (machine_id)',
            'production_plan_items_work_order_id_index' => 'production_plan_items (work_order_id)',
            'role_user_user_id_index' => 'role_user (user_id)',
            'role_permission_permission_id_index' => 'role_permission (permission_id)',
        ];

        foreach ($foreignKeys as $name => $columns) {
            $this->createIndex($name, $columns);
            $this->createdIndexes[] = $name;
        }

        // ── P3: index FIFO part_stocks ────────────────────────────────────
        $this->createIndex(
            'part_stocks_active_fifo_index',
            'part_stocks (part_id, received_at ASC NULLS LAST, id) WHERE qty > 0 AND deleted_at IS NULL',
        );
        $this->createdIndexes[] = 'part_stocks_active_fifo_index';

        // Varian UOM: query consumeFifoByUom menormalkan unit ke UPPER.
        $this->createIndex(
            'part_stocks_active_uom_fifo_index',
            'part_stocks (part_id, UPPER(COALESCE(qty_unit, \'\')), received_at ASC NULLS LAST, id) WHERE qty > 0 AND deleted_at IS NULL',
        );
        $this->createdIndexes[] = 'part_stocks_active_uom_fifo_index';

        // ── P4: partial index soft-delete ─────────────────────────────────
        $this->createIndex(
            'incoming_receives_arrival_item_active_index',
            'incoming_receives (arrival_item_id) WHERE deleted_at IS NULL',
        );
        $this->createdIndexes[] = 'incoming_receives_arrival_item_active_index';

        $this->createIndex(
            'incoming_receives_part_active_index',
            'incoming_receives (part_id) WHERE deleted_at IS NULL',
        );
        $this->createdIndexes[] = 'incoming_receives_part_active_index';

        $this->createIndex(
            'incoming_arrival_items_arrival_active_index',
            'incoming_arrival_items (arrival_id) WHERE deleted_at IS NULL',
        );
        $this->createdIndexes[] = 'incoming_arrival_items_arrival_active_index';

        // Segarkan statistik planner setelah perubahan struktur.
        $this->exec('ANALYZE parts, bom_items, work_order_items, work_order_consumptions, part_stocks, incoming_arrivals, incoming_arrival_items, incoming_receives, purchase_order_items, production_plan_items');
    }

    public function down(): void
    {
        foreach (array_reverse($this->createdIndexes) as $name) {
            $this->exec("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }

        // Pulihkan index redundan yang dihapus pada up().
        $this->exec('CREATE INDEX CONCURRENTLY IF NOT EXISTS incoming_arrivals_arrival_no_index ON incoming_arrivals (arrival_no)');
    }

    private function createIndex(string $name, string $definition): void
    {
        $this->exec("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$definition}");
    }

    private function exec(string $sql): void
    {
        DB::connection()->unprepared($sql);
    }
};
