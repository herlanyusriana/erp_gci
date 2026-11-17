<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Kosongkan seluruh data transaksi untuk mulai trial, tanpa menyentuh master.
 *
 * Master (part, supplier, customer, UOM, mesin, proses, BOM, lokasi, troli,
 * user/role, konfigurasi, harga) dibiarkan utuh. Semua dokumen dan pergerakan
 * (PO, kedatangan, penerimaan, stok, WO, produksi, issue, picking, pengiriman)
 * dihapus sehingga penomoran mulai lagi dari 0001.
 *
 * Tanpa `--force` perintah ini hanya menampilkan rencana (dry run).
 */
class ResetTransactionalData extends Command
{
    protected $signature = 'erp:reset-data
        {--force : Benar-benar hapus (tanpa ini hanya dry run)}
        {--keep-sessions : Jangan hapus sesi/token, sehingga user tetap login}';

    protected $description = 'Kosongkan data transaksi untuk trial; master data tetap';

    /**
     * Tabel transaksi: dokumen, pergerakan stok, dan hasil produksi.
     *
     * @var list<string>
     */
    private const TRANSACTIONAL = [
        // Pengiriman barang jadi
        'delivery_scans',
        'delivery_items',
        'deliveries',
        // Picking list
        'picking_list_items',
        'picking_lists',
        // Issue material
        'material_issue_items',
        'material_issues',
        // Produksi
        'production_material_receipts',
        'production_results',
        'production_plan_histories',
        'production_plan_items',
        'production_plans',
        'work_order_consumptions',
        'work_order_material_bookings',
        'work_order_item_allocations',
        'work_order_items',
        'work_orders',
        'machine_downtimes',
        // Penerimaan
        'incoming_receive_packings',
        'incoming_receives',
        'incoming_arrival_container_inspections',
        'incoming_arrival_containers',
        'incoming_arrival_items',
        'incoming_arrivals',
        'purchase_order_items',
        'purchase_orders',
        // Stok (ledger) — dihapus terakhir karena banyak tabel menunjuk ke sini
        'part_stocks',
    ];

    /**
     * Tabel master: tidak boleh ikut terhapus.
     *
     * @var list<string>
     */
    private const MASTER = [
        'parts', 'part_types', 'part_substitutes', 'part_prices', 'part_packing_types',
        'boms', 'bom_items',
        'suppliers', 'customers', 'customer_prices', 'trucking_companies',
        'uoms', 'machines', 'machine_process', 'machine_cycle_times', 'processes',
        'locations', 'trolleys', 'config_masters',
        'roles', 'permissions', 'role_permission', 'role_user', 'users',
    ];

    /** @var list<string> */
    private const INFRASTRUCTURE = ['personal_access_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $tables = $this->existing(self::TRANSACTIONAL);
        $infra = $this->existing(self::INFRASTRUCTURE);

        if ($this->option('keep-sessions')) {
            $infra = array_values(array_diff($infra, ['personal_access_tokens', 'sessions']));
        }

        $this->info('Rencana reset data (master data tetap):');
        $this->newLine();

        $rows = [];
        $total = 0;

        foreach ([...$tables, ...$infra] as $table) {
            $count = (int) DB::table($table)->count();
            $total += $count;
            $rows[] = [$table, $count, in_array($table, $infra, true) ? 'infrastruktur' : 'transaksi'];
        }

        $this->table(['Tabel', 'Baris', 'Jenis'], $rows);
        $this->line(sprintf('Total %d baris pada %d tabel.', $total, count($rows)));

        $masters = $this->existing(self::MASTER);
        $masterRows = [];

        foreach ($masters as $table) {
            $masterRows[] = [$table, (int) DB::table($table)->count()];
        }

        $this->newLine();
        $this->info('Master data yang dipertahankan:');
        $this->table(['Tabel', 'Baris'], $masterRows);

        if ($this->guardAgainstMasterLoss($tables) !== null) {
            return self::FAILURE;
        }

        if (! $force) {
            $this->newLine();
            $this->warn('Dry run. Tidak ada data yang dihapus.');
            $this->line('Jalankan ulang dengan --force untuk benar-benar menghapus.');

            return self::SUCCESS;
        }

        // `--force` adalah pernyataan niat eksplisit (seperti migrate:fresh --force),
        // jadi tidak ada prompt tambahan agar aman dipakai di skrip.
        $this->newLine();
        $this->warn(sprintf('Menghapus %d baris transaksi...', $total));

        try {
            // Satu statement: Postgres mengunci semuanya sekaligus, dan CASCADE
            // hanya menyentuh tabel yang benar-benar menunjuk ke tabel transaksi.
            DB::statement(sprintf(
                'TRUNCATE TABLE %s RESTART IDENTITY CASCADE',
                implode(', ', array_map(fn (string $t): string => '"'.$t.'"', [...$tables, ...$infra])),
            ));
        } catch (Throwable $e) {
            $this->error('Gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Selesai. Data transaksi kosong, master data utuh.');

        return self::SUCCESS;
    }

    /**
     * Pastikan tidak ada tabel master yang menunjuk ke tabel transaksi —
     * kalau ada, CASCADE akan ikut menghapus master.
     *
     * @param  list<string>  $tables
     */
    private function guardAgainstMasterLoss(array $tables): ?string
    {
        $masters = $this->existing(self::MASTER);
        $violations = [];

        foreach ($masters as $master) {
            $references = Schema::getForeignKeys($master);

            foreach ($references as $foreign) {
                if (in_array($foreign['foreign_table'], $tables, true)) {
                    $violations[] = $master.' -> '.$foreign['foreign_table'];
                }
            }
        }

        if ($violations === []) {
            return null;
        }

        $this->error('Dibatalkan: tabel master berikut menunjuk ke tabel transaksi,');
        $this->error('sehingga TRUNCATE ... CASCADE akan menghapus master data:');

        foreach ($violations as $violation) {
            $this->line('  - '.$violation);
        }

        return 'master-loss';
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function existing(array $tables): array
    {
        return array_values(array_filter($tables, fn (string $table): bool => Schema::hasTable($table)));
    }
}
