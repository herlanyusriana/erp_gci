<?php

namespace Tests\Feature;

use App\Console\Commands\ResetTransactionalData;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\PickingList;
use App\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class ResetTransactionalDataTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);
    }

    /** Siapkan satu dokumen transaksi supaya ada yang bisa dihapus. */
    private function seedTransaction(): void
    {
        $part = Part::where('part_number', 'AAN30056405')->firstOrFail();

        PartStock::create([
            'part_id' => $part->id,
            'tag' => 'TAG-RESET-'.uniqid(),
            'qty' => 10,
            'qty_unit' => 'PCS',
            'received_at' => now(),
        ]);

        PickingList::create([
            'picking_no' => PickingList::generatePickingNo(now()),
            'picking_date' => now()->toDateString(),
            'status' => PickingList::STATUS_DRAFT,
            'created_by' => $this->admin->id,
        ]);

        Delivery::create([
            'delivery_no' => Delivery::generateDeliveryNo(now()),
            'customer_id' => Customer::query()->value('id') ?? Customer::create([
                'customer_code' => 'RESET-CUST',
                'customer_name' => 'Reset Customer',
                'is_active' => true,
            ])->id,
            'delivery_date' => now()->toDateString(),
            'status' => Delivery::STATUS_DRAFT,
        ]);
    }

    public function test_dry_run_reports_but_keeps_everything(): void
    {
        $this->seedTransaction();

        $before = [
            'part_stocks' => DB::table('part_stocks')->count(),
            'picking_lists' => DB::table('picking_lists')->count(),
            'deliveries' => DB::table('deliveries')->count(),
        ];

        $this->artisan('erp:reset-data')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame($before['part_stocks'], DB::table('part_stocks')->count());
        $this->assertSame($before['picking_lists'], DB::table('picking_lists')->count());
        $this->assertSame($before['deliveries'], DB::table('deliveries')->count());
    }

    public function test_force_clears_transactions_and_keeps_master_data(): void
    {
        $this->seedTransaction();

        $master = [
            'parts' => DB::table('parts')->count(),
            'part_types' => DB::table('part_types')->count(),
            'part_substitutes' => DB::table('part_substitutes')->count(),
            'boms' => DB::table('boms')->count(),
            'bom_items' => DB::table('bom_items')->count(),
            'suppliers' => DB::table('suppliers')->count(),
            'customers' => DB::table('customers')->count(),
            'uoms' => DB::table('uoms')->count(),
            'machines' => DB::table('machines')->count(),
            'processes' => DB::table('processes')->count(),
            'locations' => DB::table('locations')->count(),
            'trolleys' => DB::table('trolleys')->count(),
            'config_masters' => DB::table('config_masters')->count(),
            'roles' => DB::table('roles')->count(),
            'permissions' => DB::table('permissions')->count(),
            'role_permission' => DB::table('role_permission')->count(),
            'role_user' => DB::table('role_user')->count(),
            'users' => DB::table('users')->count(),
        ];

        $this->artisan('erp:reset-data', ['--force' => true])->assertSuccessful();

        foreach ([
            'delivery_scans', 'delivery_items', 'deliveries',
            'picking_list_items', 'picking_lists',
            'material_issue_items', 'material_issues',
            'production_results', 'production_plan_items', 'production_plans',
            'work_order_consumptions', 'work_order_material_bookings', 'work_order_item_allocations',
            'work_order_items', 'work_orders',
            'incoming_receives', 'incoming_arrival_items', 'incoming_arrivals',
            'purchase_order_items', 'purchase_orders',
            'part_stocks',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "Tabel {$table} harus kosong");
        }

        foreach ($master as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "Master {$table} harus utuh");
        }

        // Penomoran mulai lagi dari 0001 karena baris lama benar-benar hilang.
        $this->assertStringEndsWith('-0001', PickingList::generatePickingNo(now()));
        $this->assertStringEndsWith('-0001', Delivery::generateDeliveryNo(now()));
    }

    public function test_keep_sessions_option_leaves_tokens_alone(): void
    {
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $this->admin->id,
            'name' => 'test-device',
            'token' => hash('sha256', 'reset-test'),
            'abilities' => '["*"]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('erp:reset-data', ['--force' => true, '--keep-sessions' => true])->assertSuccessful();

        $this->assertSame(1, DB::table('personal_access_tokens')->count());
    }

    public function test_force_is_refused_outside_local_and_testing_without_i_know(): void
    {
        $this->seedTransaction();

        $before = [
            'part_stocks' => DB::table('part_stocks')->count(),
            'picking_lists' => DB::table('picking_lists')->count(),
            'deliveries' => DB::table('deliveries')->count(),
        ];

        // Simulasi server produksi: environment container diganti langsung,
        // tanpa mengubah APP_ENV di phpunit.xml. Selalu dipulihkan di finally
        // supaya test lain tetap berjalan sebagai `testing`.
        $this->app['env'] = 'production';

        try {
            $this->artisan('erp:reset-data', ['--force' => true])
                ->expectsOutputToContain('--i-know')
                ->assertFailed();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame($before['part_stocks'], DB::table('part_stocks')->count());
        $this->assertSame($before['picking_lists'], DB::table('picking_lists')->count());
        $this->assertSame($before['deliveries'], DB::table('deliveries')->count());
    }

    public function test_force_with_i_know_still_runs_outside_local_and_testing(): void
    {
        $this->seedTransaction();

        // Sama seperti test di atas: environment diganti langsung di container.
        $this->app['env'] = 'production';

        try {
            $this->artisan('erp:reset-data', ['--force' => true, '--i-know' => true])
                ->assertSuccessful();
        } finally {
            $this->app['env'] = 'testing';
        }

        foreach (['deliveries', 'picking_lists', 'part_stocks'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "Tabel {$table} harus kosong");
        }
    }

    public function test_guard_blocks_when_master_points_at_transaction_table(): void
    {
        $command = new ResetTransactionalData;
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(
            new ArrayInput([]),
            new NullOutput,
        ));

        $method = new \ReflectionMethod($command, 'guardAgainstMasterLoss');

        // `parts` bukan tabel transaksi: guard harus lolos (null).
        $this->assertNull($method->invoke($command, ['part_stocks']));

        // Kalau `parts` salah dimasukkan sebagai transaksi, guard harus menolak
        // karena part_substitutes / boms / part_prices menunjuk ke parts.
        $this->assertSame('master-loss', $method->invoke($command, ['parts', 'part_stocks']));
    }
}
