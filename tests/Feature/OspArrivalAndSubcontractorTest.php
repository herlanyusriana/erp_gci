<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\IncomingArrival;
use App\Models\IncomingReceive;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\Subcontractor;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OspArrivalAndSubcontractorTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);
    }

    private function makeSubcontractor(string $suffix = ''): Subcontractor
    {
        $code = 'SUB-'.strtoupper(uniqid()).$suffix;

        return Subcontractor::create([
            'subcontractor_code' => $code,
            'subcontractor_name' => 'PT Subcont '.$code,
            'address' => 'Jl. Kawasan Industri 12',
            'phone' => '021-8889900',
            'email' => strtolower($code).'@subcont.local',
            'contact_person' => 'Andi Wijaya',
            'npwp' => '01.234.567.8-901.000',
            'bc_facility' => Subcontractor::BC_KB,
            'process_notes' => 'Plating & Hardening',
            'is_active' => true,
        ]);
    }

    private function makeCustomer(string $suffix = ''): Customer
    {
        $code = 'CUST-'.strtoupper(uniqid()).$suffix;

        return Customer::create([
            'customer_code' => $code,
            'customer_name' => 'PT Customer '.$code,
            'short_name' => 'CUST',
            'address' => 'Jl. Raya Cikarang 88',
            'phone' => '021-7776655',
            'contact_person' => 'Siti Nur',
            'email' => strtolower($code).'@customer.local',
            'bc_facility' => Customer::BC_KB,
            'is_active' => true,
        ]);
    }

    public function test_subcontractor_master_crud_and_delete_guard(): void
    {
        $this->get(route('subcontractors.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Master/Subcontractor/Index'));

        $code = 'SUB-CRUD-'.strtoupper(uniqid());
        $this->post(route('subcontractors.store'), [
            'subcontractor_code' => $code,
            'subcontractor_name' => 'PT Subcont Maju',
            'address' => 'Jl. Jababeka IV',
            'phone' => '021-8901234',
            'email' => 'pic@subcont-maju.local',
            'contact_person' => 'Budi',
            'npwp' => '12.345.678.9-012.000',
            'bc_facility' => 'KB',
            'process_notes' => 'Plating Chrome',
            'is_active' => true,
        ])->assertRedirect(route('subcontractors.index'))->assertSessionHasNoErrors();

        $sub = Subcontractor::where('subcontractor_code', $code)->firstOrFail();

        $this->get(route('subcontractors.index', ['search' => $code]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/Subcontractor/Index')
                ->where('subcontractors.data.0.id', $sub->id));

        $this->put(route('subcontractors.update', $sub), [
            'subcontractor_code' => $code,
            'subcontractor_name' => 'PT Subcont Maju Jaya',
            'bc_facility' => 'NON-KB',
            'process_notes' => 'Plating & Heat Treatment',
            'is_active' => true,
        ])->assertRedirect(route('subcontractors.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('subcontractors', [
            'id' => $sub->id,
            'subcontractor_name' => 'PT Subcont Maju Jaya',
            'bc_facility' => 'NON-KB',
        ]);

        // Peran production tidak boleh membuat master subcont.
        $production = User::where('email', 'production@geumcheon.local')->firstOrFail();
        $this->actingAs($production)
            ->post(route('subcontractors.store'), [
                'subcontractor_code' => 'SUB-DENIED-'.uniqid(),
                'subcontractor_name' => 'Denied',
            ])
            ->assertForbidden();

        // Hapus subcont yang belum dipakai kedatangan → berhasil.
        $this->actingAs($this->admin)
            ->delete(route('subcontractors.destroy', $sub))
            ->assertRedirect(route('subcontractors.index'));
        $this->assertSoftDeleted('subcontractors', ['id' => $sub->id]);

        // Subcont yang sudah dipakai di kedatangan OSP GCI tidak boleh dihapus.
        $used = $this->makeSubcontractor('-USED');
        IncomingArrival::create([
            'arrival_no' => 'OSP-USED-'.uniqid(),
            'arrival_type' => IncomingArrival::TYPE_OSP_GCI,
            'is_local' => false,
            'subcontractor_id' => $used->id,
            'status' => 'pending',
        ]);

        $this->delete(route('subcontractors.destroy', $used))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('subcontractors', [
            'id' => $used->id,
            'deleted_at' => null,
        ]);
    }

    public function test_osp_gci_arrival_creation_update_and_bom_enforcement(): void
    {
        $sub = $this->makeSubcontractor('-GCI');
        $subconSourcePart = Part::where('part_number', 'MAZ65643601-WIP2')->firstOrFail();
        $subconMachinePart = Part::where('part_number', 'PINHC0101')->firstOrFail();
        $nonSubconPart = Part::where('part_number', '4000W4A003A')->firstOrFail();

        $this->post(route('incoming-arrivals.store'), [
            'arrival_type' => IncomingArrival::TYPE_OSP_GCI,
            'subcontractor_id' => $sub->id,
            'contract_no' => 'KTR-GCI-001',
            'reference_no' => 'REF-GCI-001',
            'delivery_note_no' => 'SJ-GCI-001',
            'invoice_date' => now()->toDateString(),
            'pen_no' => 'PEN-GCI',
            'aju_no' => 'AJU-GCI',
            'currency' => 'IDR',
            'items' => [
                [
                    'part_id' => $subconSourcePart->id,
                    'qty_goods' => 100,
                    'unit_goods' => 'PCS',
                    'total_price' => 250000.5,
                    'price' => 2500.005,
                    'is_foc' => false,
                ],
                [
                    'part_id' => $subconMachinePart->id,
                    'qty_goods' => 40,
                    'unit_goods' => 'PCS',
                    'is_foc' => true,
                ],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $arrival = IncomingArrival::where('contract_no', 'KTR-GCI-001')->firstOrFail();

        $this->assertStringStartsWith('OSP-', $arrival->arrival_no);
        $this->assertSame(IncomingArrival::TYPE_OSP_GCI, $arrival->arrival_type);
        $this->assertFalse($arrival->is_local);
        $this->assertNull($arrival->supplier_id);
        $this->assertSame($sub->id, $arrival->subcontractor_id);
        $this->assertSame('REF-GCI-001', $arrival->reference_no);
        $this->assertSame(2, $arrival->items()->count());

        // Update kontrak tetap menjaga tipe OSP GCI.
        $this->put(route('incoming-arrivals.update', $arrival), [
            'arrival_type' => IncomingArrival::TYPE_OSP_GCI,
            'subcontractor_id' => $sub->id,
            'contract_no' => 'KTR-GCI-002',
            'reference_no' => 'REF-GCI-002',
            'delivery_note_no' => 'SJ-GCI-001',
            'currency' => 'IDR',
            'status' => 'pending',
            'items' => [
                [
                    'id' => $arrival->items()->first()->id,
                    'part_id' => $subconSourcePart->id,
                    'qty_goods' => 120,
                    'unit_goods' => 'PCS',
                    'is_foc' => false,
                ],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $arrival->refresh();
        $this->assertSame(IncomingArrival::TYPE_OSP_GCI, $arrival->arrival_type);
        $this->assertSame('KTR-GCI-002', $arrival->contract_no);

        // Cetak invoice HTML menampilkan nama subcont.
        $this->get(route('incoming-arrivals.invoice', $arrival))
            ->assertOk()
            ->assertSee(strtoupper($sub->subcontractor_name));

        // Part non-Subcon di BOM ditolak untuk OSP GCI.
        $this->post(route('incoming-arrivals.store'), [
            'arrival_type' => IncomingArrival::TYPE_OSP_GCI,
            'subcontractor_id' => $sub->id,
            'delivery_note_no' => 'SJ-GCI-ERR',
            'items' => [
                [
                    'part_id' => $nonSubconPart->id,
                    'qty_goods' => 10,
                    'unit_goods' => 'PCS',
                ],
            ],
        ])->assertSessionHasErrors('items.0.part_id');
    }

    public function test_osp_customer_arrival_creation_and_bom_enforcement(): void
    {
        $customer = $this->makeCustomer('-OSP');
        $freeIssuePart = Part::where('part_number', '4000W4A003A')->firstOrFail();
        $subconOnlyPart = Part::where('part_number', 'MAZ65643601-WIP2')->firstOrFail();

        $this->post(route('incoming-arrivals.store'), [
            'arrival_type' => IncomingArrival::TYPE_OSP_CUSTOMER,
            'customer_id' => $customer->id,
            'contract_no' => 'KTR-CUST-001',
            'delivery_note_no' => 'SJ-CUST-001',
            'invoice_date' => now()->toDateString(),
            'currency' => 'IDR',
            'items' => [
                [
                    'part_id' => $freeIssuePart->id,
                    'qty_goods' => 50,
                    'unit_goods' => 'PCS',
                    'is_foc' => true,
                ],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $arrival = IncomingArrival::where('contract_no', 'KTR-CUST-001')->firstOrFail();

        $this->assertStringStartsWith('OSP-', $arrival->arrival_no);
        $this->assertSame(IncomingArrival::TYPE_OSP_CUSTOMER, $arrival->arrival_type);
        $this->assertFalse($arrival->is_local);
        $this->assertNull($arrival->supplier_id);
        $this->assertNull($arrival->subcontractor_id);
        $this->assertSame($customer->id, $arrival->customer_id);

        // Part yang bukan FREE_ISSUE / OSP di BOM ditolak.
        $this->post(route('incoming-arrivals.store'), [
            'arrival_type' => IncomingArrival::TYPE_OSP_CUSTOMER,
            'customer_id' => $customer->id,
            'delivery_note_no' => 'SJ-CUST-ERR',
            'items' => [
                [
                    'part_id' => $subconOnlyPart->id,
                    'qty_goods' => 10,
                    'unit_goods' => 'PCS',
                ],
            ],
        ])->assertSessionHasErrors('items.0.part_id');

        // Customer yang terhubung ke OSP Customer arrival tidak boleh dihapus.
        $this->delete(route('customers.destroy', $customer))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'deleted_at' => null,
        ]);
    }

    public function test_osp_arrivals_do_not_appear_in_local_po_list(): void
    {
        $sub = $this->makeSubcontractor('-NOLPO');
        $subconPart = Part::where('part_number', 'MAZ65643601-WIP2')->firstOrFail();

        $this->post(route('incoming-arrivals.store'), [
            'arrival_type' => IncomingArrival::TYPE_OSP_GCI,
            'subcontractor_id' => $sub->id,
            'contract_no' => 'KTR-NOLPO-01',
            'delivery_note_no' => 'SJ-NOLPO-01',
            'items' => [
                [
                    'part_id' => $subconPart->id,
                    'qty_goods' => 10,
                    'unit_goods' => 'PCS',
                ],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $ospArrival = IncomingArrival::where('contract_no', 'KTR-NOLPO-01')->firstOrFail();

        $this->get(route('local-pos.index', ['search' => $ospArrival->arrival_no]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incoming/LocalPo/Index')
                ->where('localPos.data', []));
    }

    public function test_osp_arrival_full_flow_iqc_receive_stock_filter_and_qr_label(): void
    {
        $sub = $this->makeSubcontractor('-FLOW');
        $customer = $this->makeCustomer('-FLOW');
        $wipPart = Part::where('part_number', 'MAZ65643601-WIP2')->firstOrFail();
        $rmPart = Part::where('part_number', '4000W4A003A')->firstOrFail();

        $this->post(route('incoming-arrivals.store'), [
            'arrival_type' => IncomingArrival::TYPE_OSP_GCI,
            'subcontractor_id' => $sub->id,
            'contract_no' => 'KTR-FLOW-GCI',
            'delivery_note_no' => 'SJ-FLOW-GCI',
            'items' => [[
                'part_id' => $wipPart->id,
                'qty_goods' => 20,
                'unit_goods' => 'PCS',
            ]],
        ])->assertSessionHasNoErrors();

        $this->post(route('incoming-arrivals.store'), [
            'arrival_type' => IncomingArrival::TYPE_OSP_CUSTOMER,
            'customer_id' => $customer->id,
            'contract_no' => 'KTR-FLOW-CUST',
            'delivery_note_no' => 'SJ-FLOW-CUST',
            'items' => [[
                'part_id' => $rmPart->id,
                'qty_goods' => 30,
                'unit_goods' => 'PCS',
            ]],
        ])->assertSessionHasNoErrors();

        $gciArrival = IncomingArrival::where('contract_no', 'KTR-FLOW-GCI')->firstOrFail();
        $custArrival = IncomingArrival::where('contract_no', 'KTR-FLOW-CUST')->firstOrFail();

        // Lolos IQC untuk kedua dokumen OSP.
        $this->post(route('incoming-quality.pass-all', $gciArrival))->assertSessionHasNoErrors();
        $this->post(route('incoming-quality.pass-all', $custArrival))->assertSessionHasNoErrors();

        $gciItem = $gciArrival->items()->firstOrFail();
        $custItem = $custArrival->items()->firstOrFail();

        $tagGci = 'TAG-OSP-GCI-'.strtoupper(uniqid());
        $tagCust = 'TAG-OSP-CUST-'.strtoupper(uniqid());

        $this->post(route('receive.store', $gciItem), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 9201 OSP',
            'location_code' => 'BULK-AREA',
            'tags' => [[
                'tag' => $tagGci,
                'qty' => 20,
                'qty_unit' => 'PCS',
                'bundle_count' => 1,
                'bundle_unit' => 'BOX',
                'packing_count' => 1,
                'packing_type' => 'BOX',
                'packing_qty' => 20,
            ]],
        ])->assertRedirect(route('receive.show', $gciArrival))->assertSessionHasNoErrors();

        $this->post(route('receive.store', $custItem), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 9202 OSP',
            'location_code' => 'BULK-AREA',
            'tags' => [[
                'tag' => $tagCust,
                'qty' => 30,
                'qty_unit' => 'PCS',
                'bundle_count' => 1,
                'bundle_unit' => 'BOX',
                'packing_count' => 1,
                'packing_type' => 'BOX',
                'packing_qty' => 30,
            ]],
        ])->assertRedirect(route('receive.show', $custArrival))->assertSessionHasNoErrors();

        // Filter Arrival Index by arrival_type.
        $this->get(route('incoming-arrivals.index', ['arrival_type' => IncomingArrival::TYPE_OSP_GCI]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incoming/Arrival/Index')
                ->where('arrivals.data', fn ($rows) => collect($rows)->every(fn ($r) => $r['arrival_type'] === IncomingArrival::TYPE_OSP_GCI)));

        // Filter Stock Index by arrival_type = osp_gci (tab WIP).
        $this->get(route('stocks.index', ['tab' => 'wip', 'arrival_type' => IncomingArrival::TYPE_OSP_GCI, 'search' => $tagGci]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incoming/Stock/Index')
                ->where('stocks.data.0.tag', $tagGci)
                ->where('stocks.data.0.receive.arrival_item.arrival.arrival_type', IncomingArrival::TYPE_OSP_GCI)
                ->where('stocks.data.0.receive.arrival_item.arrival.subcontractor.subcontractor_name', $sub->subcontractor_name));

        // Filter Stock Index by arrival_type = osp_customer (tab Material).
        $this->get(route('stocks.index', ['tab' => 'material', 'arrival_type' => IncomingArrival::TYPE_OSP_CUSTOMER, 'search' => $tagCust]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incoming/Stock/Index')
                ->where('stocks.data.0.tag', $tagCust)
                ->where('stocks.data.0.receive.arrival_item.arrival.arrival_type', IncomingArrival::TYPE_OSP_CUSTOMER)
                ->where('stocks.data.0.receive.arrival_item.arrival.customer.customer_name', $customer->customer_name));

        // Pastikan baris non-OSP tidak lolos filter arrival_type.
        $this->assertSame(1, PartStock::where('tag', $tagGci)->count());

        // Label QR mencetak badge OSP & pengirim masing-masing.
        $gciReceive = IncomingReceive::where('tag', $tagGci)->firstOrFail();
        $custReceive = IncomingReceive::where('tag', $tagCust)->firstOrFail();

        $this->get(route('receive.label', $gciReceive))
            ->assertOk()
            ->assertSee('OSP GCI')
            ->assertSee($sub->subcontractor_name);

        $this->get(route('receive.label', $custReceive))
            ->assertOk()
            ->assertSee('OSP CUSTOMER')
            ->assertSee($customer->customer_name);
    }

    public function test_arrival_create_exposes_model_in_osp_part_options(): void
    {
        $this->get(route('incoming-arrivals.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incoming/Arrival/Form')
                ->where('ospGciParts', function ($parts) {
                    $row = collect($parts)->firstWhere('part_number', 'MAZ65643601-WIP2');

                    return $row !== null && array_key_exists('model', $row) && ! empty($row['model']);
                })
                ->where('ospCustomerParts', function ($parts) {
                    $row = collect($parts)->firstWhere('part_number', '4000W4A003A');

                    return $row !== null && array_key_exists('model', $row);
                }));
    }

    public function test_reset_transactional_data_preserves_subcontractors_master(): void
    {
        $sub = $this->makeSubcontractor('-RESET');

        IncomingArrival::create([
            'arrival_no' => 'OSP-RESET-'.uniqid(),
            'arrival_type' => IncomingArrival::TYPE_OSP_GCI,
            'is_local' => false,
            'subcontractor_id' => $sub->id,
            'status' => 'pending',
        ]);

        $this->artisan('erp:reset-data', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('incoming_arrivals')->count());
        $this->assertDatabaseHas('subcontractors', [
            'id' => $sub->id,
            'subcontractor_code' => $sub->subcontractor_code,
        ]);
    }
}
