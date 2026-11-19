<?php

namespace Tests\Feature;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\IncomingReceive;
use App\Models\PartSubstitute;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TieredReceiveTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);
    }

    private function createArrivalWithItem(string $unit = 'EA', ?float $weightNett = null): array
    {
        $supplier = Supplier::where('is_active', true)
            ->whereHas('partSubstitutes', fn ($q) => $q->where('is_active', true))
            ->firstOrFail();
        $part = $supplier->partSubstitutes()->where('is_active', true)->firstOrFail()->substitutePart;

        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-TIER-'.uniqid(),
            'supplier_id' => $supplier->id,
            'is_local' => false,
            'status' => 'pending',
            'iqc_status' => 'passed',
        ]);

        $item = IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $part->id,
            'material_group' => 'MAT-TIER',
            'size' => 'STD',
            'qty_goods' => 30000,
            'unit_goods' => $unit,
            'weight_nett' => $weightNett,
        ]);

        return [$arrival, $item, $part];
    }

    public function test_receive_stores_tiered_packaging_attributes_and_calculates_total(): void
    {
        [$arrival, $item] = $this->createArrivalWithItem('EA');

        // Input 2 Pallet x 5 Box x 2000 EA = 20.000 EA
        $response = $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 9001 GCI',
            'tags' => [
                [
                    'tag' => 'TAG-TIER-01',
                    'bundle_count' => 2,
                    'bundle_unit' => 'PALLET',
                    'packing_count' => 5,
                    'packing_type' => 'BOX',
                    'packing_qty' => 2000,
                    'qty' => 20000,
                    'qty_unit' => 'EA',
                    'net_weight' => 500,
                    'gross_weight' => 550,
                ],
            ],
        ]);

        $response->assertRedirect(route('receive.show', $arrival));

        $this->assertDatabaseHas('incoming_receives', [
            'arrival_item_id' => $item->id,
            'tag' => 'TAG-TIER-01',
            'bundle_count' => 2,
            'bundle_unit' => 'PALLET',
            'packing_count' => 5,
            'packing_type' => 'BOX',
            'packing_qty' => 2000,
            'qty' => 20000,
            'net_weight' => 500,
            'gross_weight' => 550,
        ]);
    }

    public function test_receive_generates_one_tag_per_bundle_with_coil_packing(): void
    {
        [$arrival, $item] = $this->createArrivalWithItem('KGM');

        // Rencana: 2 PALLET @ 5 COIL @ 500 KGM. Satu tag = satu bundle.
        $response = $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 9001 GCI',
            'tags' => [
                [
                    'tag' => 'INV-COIL-B01',
                    'bundle_count' => 1,
                    'bundle_unit' => 'pallet',
                    'packing_count' => 5,
                    'packing_type' => 'coil',
                    'packing_qty' => 500,
                    'qty' => 2500,
                    'qty_unit' => 'KGM',
                ],
                [
                    'tag' => 'INV-COIL-B02',
                    'bundle_count' => 1,
                    'bundle_unit' => 'pallet',
                    'packing_count' => 5,
                    'packing_type' => 'coil',
                    'packing_qty' => 500,
                    'qty' => 2500,
                    'qty_unit' => 'KGM',
                ],
            ],
        ]);

        $response->assertRedirect(route('receive.show', $arrival));

        $this->assertSame(2, $item->receives()->count());
        foreach ($item->receives()->orderBy('tag')->get() as $receive) {
            $this->assertEquals(1, $receive->bundle_count);
            $this->assertSame('PALLET', $receive->bundle_unit);
            $this->assertEquals(5, $receive->packing_count);
            $this->assertSame('COIL', $receive->packing_type);
            $this->assertEquals(500, $receive->packing_qty);
            $this->assertEquals(2500, $receive->qty);
        }

        $first = $item->receives()->orderBy('tag')->firstOrFail();
        $this->get(route('receive.label', $first))
            ->assertOk()
            // 1 tag = 1 bundle: baris bundle membawa total, baris packing per unit.
            ->assertSee('1 PALLET')
            ->assertSee('2,500 KGM')
            ->assertSee('5 COIL @ 500 KGM');
    }

    public function test_receive_generates_auto_tag_when_identification_is_blank(): void
    {
        [$arrival, $item] = $this->createArrivalWithItem('EA');

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 9001 GCI',
            'tags' => [
                [
                    'tag' => '',
                    'bundle_count' => 1,
                    'bundle_unit' => 'PALLET',
                    'packing_count' => 5,
                    'packing_type' => 'BOX',
                    'packing_qty' => 2000,
                    'qty' => 10000,
                    'qty_unit' => 'EA',
                ],
            ],
        ])->assertRedirect(route('receive.show', $arrival));

        $receive = $item->receives()->firstOrFail();

        // Format: AUTO-<ymd>-<id receive>, id di-pad minimal 4 digit. Id bisa
        // melewati 9999 pada database yang sudah lama dipakai, jadi lebar
        // padding tidak boleh diasumsikan tepat 4 digit.
        $expected = 'AUTO-'.now()->format('ymd').'-'.str_pad((string) $receive->id, 4, '0', STR_PAD_LEFT);

        $this->assertSame($expected, (string) $receive->tag);
        $this->assertMatchesRegularExpression('/^AUTO-\d{6}-\d{4,}$/', (string) $receive->tag);
    }

    public function test_receive_stores_pen_and_aju_on_the_arrival(): void
    {
        [$arrival, $item] = $this->createArrivalWithItem('EA');

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 9001 GCI',
            'pen_no' => 'PEN-RECV-1',
            'aju_no' => 'AJU-RECV-1',
            'tags' => [
                [
                    'tag' => 'TAG-PEN-1',
                    'qty' => 1000,
                    'qty_unit' => 'EA',
                ],
            ],
        ])->assertRedirect(route('receive.show', $arrival));

        $arrival->refresh();
        $this->assertSame('PEN-RECV-1', $arrival->pen_no);
        $this->assertSame('AJU-RECV-1', $arrival->aju_no);
    }

    public function test_arrival_show_page_no_longer_renders_receive_buttons_or_receive_tags_table(): void
    {
        [$arrival, $item] = $this->createArrivalWithItem('EA');

        IncomingReceive::create([
            'arrival_item_id' => $item->id,
            'part_id' => $item->part_id,
            'tag' => 'TAG-ARV-HIDE',
            'qty' => 1000,
            'qty_unit' => 'EA',
            'qc_status' => 'pass',
            'ata_date' => now(),
        ]);

        $response = $this->get(route('incoming-arrivals.show', $arrival))->assertOk();

        // Tidak boleh ada tombol receive.create di halaman Arrival
        $response->assertDontSee(route('receive.create', $item));
        // Tidak boleh ada tag receive di halaman Arrival
        $response->assertDontSee('TAG-ARV-HIDE');
    }

    public function test_receive_workspace_shows_items_and_tags_and_allows_receiving(): void
    {
        [$arrival, $item] = $this->createArrivalWithItem('EA');

        $receive = IncomingReceive::create([
            'arrival_item_id' => $item->id,
            'part_id' => $item->part_id,
            'tag' => 'TAG-RECV-SHOW',
            'qty' => 5000,
            'qty_unit' => 'EA',
            'qc_status' => 'pass',
            'ata_date' => now(),
        ]);

        $response = $this->get(route('receive.show', $arrival))->assertOk();

        // Halaman Receive Show menampilkan data kedatangan, item, dan tag receive
        $response->assertInertia(fn ($page) => $page
            ->component('Incoming/Receive/Show')
            ->where('arrival.id', $arrival->id)
            ->where('pending.0.item.id', $item->id)
            ->where('pending.0.remaining', 25000)
            ->where('arrival.items.0.receives.0.tag', 'TAG-RECV-SHOW')
        );

        $this->get(route('receive.label', $receive))->assertOk();
    }

    public function test_label_renders_packaging_breakdown_string(): void
    {
        [$arrival, $item] = $this->createArrivalWithItem('EA');

        $receive = IncomingReceive::create([
            'arrival_item_id' => $item->id,
            'part_id' => $item->part_id,
            'tag' => 'TAG-LABEL-TIER',
            'bundle_count' => 2,
            'bundle_unit' => 'PALLET',
            'packing_count' => 5,
            'packing_type' => 'BOX',
            'packing_qty' => 2000,
            'qty' => 20000,
            'qty_unit' => 'EA',
            'qc_status' => 'pass',
            'ata_date' => now(),
        ]);

        $response = $this->get(route('receive.label', $receive))->assertOk();

        // Label memisahkan baris bundle (total) dari baris packing (per unit)
        $response->assertSee('2 PALLET')
            ->assertSee('20,000 EA')
            ->assertSee('5 BOX @ 2,000 EA');
    }

    public function test_receive_stores_sub_packings_per_bundle(): void
    {
        [$arrival, $item] = $this->createArrivalWithItem('KGM');

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 9001 GCI',
            'driver_name' => 'Slamet',
            'location_code' => 'BULK-AREA',
            'tags' => [
                [
                    'tag' => 'TAG-SUB-01',
                    'qty' => 3000,
                    'qty_unit' => 'KGM',
                    'bundle_count' => 1,
                    'bundle_unit' => 'PALLET',
                    'packing_count' => 3,
                    'packing_type' => 'COIL',
                    'packing_qty' => 1000,
                    'net_weight' => 3000,
                    'gross_weight' => 3100,
                    'packings' => [
                        ['packing_no' => 'TAG-SUB-01/1', 'sequence' => 1, 'qty' => 1000, 'uom' => 'KGM', 'net_weight' => 1000, 'gross_weight' => 1030],
                        ['packing_no' => 'TAG-SUB-01/2', 'sequence' => 2, 'qty' => 1000, 'uom' => 'KGM', 'net_weight' => 1000, 'gross_weight' => 1035],
                        ['packing_no' => 'TAG-SUB-01/3', 'sequence' => 3, 'qty' => 1000, 'uom' => 'KGM', 'net_weight' => 1000, 'gross_weight' => 1035],
                    ],
                ],
            ],
        ])->assertRedirect(route('receive.show', $arrival));

        $receive = IncomingReceive::where('tag', 'TAG-SUB-01')->firstOrFail();

        $this->assertSame(3, $receive->packings()->count());
        $this->assertSame('BULK-AREA', $receive->location_code);
        $this->assertSame('B 9001 GCI', $receive->truck_no);

        $first = $receive->packings()->orderBy('sequence')->first();
        $this->assertSame('TAG-SUB-01/1', $first->packing_no);
        $this->assertEqualsWithDelta(1000.0, $first->qty, 1e-6);
        $this->assertSame('KGM', $first->uom);
        $this->assertEqualsWithDelta(1030.0, $first->gross_weight, 1e-6);
    }

    public function test_receive_requires_a_truck_number(): void
    {
        [$arrival, $item] = $this->createArrivalWithItem('EA');

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'tags' => [
                ['tag' => 'TAG-NOTRUCK', 'qty' => 100, 'qty_unit' => 'EA'],
            ],
        ])->assertSessionHasErrors('truck_no');

        $this->assertSame(0, $item->receives()->count());
    }

    public function test_receive_form_defaults_to_bulk_area_and_exposes_packing_catalogs(): void
    {
        [$arrival, $item] = $this->createArrivalWithItem('EA');

        $this->get(route('receive.create', $item))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Incoming/Receive/Form')
                ->where('defaultLocation', 'BULK-AREA')
                ->where('packingTypes', fn ($types) => collect($types)->contains('COIL'))
                ->where('packingUnits', fn ($units) => collect($units)->contains('PALLET')));
    }

    /**
     * Nomor packing ({tag}/{n}) tetap dibuat otomatis dan tersimpan, tetapi
     * tidak dicetak di label. Pada bundle berisi puluhan packing deret nomornya
     * memenuhi separuh label dan tidak dibaca operator di rak; ringkasan
     * "2 COIL @ 1,000 KGM" sudah cukup.
     */
    public function test_label_summarizes_packings_without_printing_packing_numbers(): void
    {
        [$arrival, $item] = $this->createArrivalWithItem('KGM');

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 9001 GCI',
            'location_code' => 'BULK-AREA',
            'tags' => [
                [
                    'tag' => 'TAG-LBL-01',
                    'qty' => 2000,
                    'qty_unit' => 'KGM',
                    'bundle_count' => 1,
                    'bundle_unit' => 'PALLET',
                    'packing_count' => 2,
                    'packing_type' => 'COIL',
                    'packing_qty' => 1000,
                    'net_weight' => 2000,
                    'packings' => [
                        ['packing_no' => 'TAG-LBL-01/1', 'sequence' => 1, 'qty' => 1000, 'uom' => 'KGM', 'net_weight' => 1000],
                        ['packing_no' => 'TAG-LBL-01/2', 'sequence' => 2, 'qty' => 1000, 'uom' => 'KGM', 'net_weight' => 1000],
                    ],
                ],
            ],
        ])->assertRedirect();

        $receive = IncomingReceive::where('tag', 'TAG-LBL-01')->firstOrFail();

        $this->get(route('receive.label', $receive))
            ->assertOk()
            // 1 tag = 1 bundle: baris bundle membawa total, packing diringkas per unit.
            ->assertSee('1 PALLET')
            ->assertSee('2,000 KGM')
            ->assertSee('2 COIL @ 1,000 KGM')
            ->assertDontSee('TAG-LBL-01/1')
            ->assertDontSee('TAG-LBL-01/2');

        expect($receive->packings()->orderBy('sequence')->pluck('packing_no')->all())
            ->toBe(['TAG-LBL-01/1', 'TAG-LBL-01/2']);
    }

    /**
     * Tag adalah identitas bundle yang diketik operator, bukan turunan nomor
     * invoice. Label harus mencetaknya apa adanya, dan invoice tetap tampil
     * sebagai rujukan dokumen di baris terpisah.
     */
    public function test_label_prints_operator_tag_verbatim_and_invoice_separately(): void
    {
        [$arrival, $item] = $this->createArrivalWithItem('EA');
        $arrival->update(['invoice_no' => 'INV/2026/01/0001']);

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 9001 GCI',
            'location_code' => 'BULK-AREA',
            'tags' => [
                [
                    'tag' => '2',
                    'bundle_count' => 1,
                    'bundle_unit' => 'PALLET',
                    'packing_count' => 1,
                    'packing_type' => 'COIL',
                    'packing_qty' => 2000,
                    'qty' => 2000,
                    'qty_unit' => 'EA',
                ],
            ],
        ]);

        $receive = IncomingReceive::where('tag', '2')->firstOrFail();
        $this->assertSame('2', $receive->tag);

        $this->get(route('receive.label', $receive))
            ->assertOk()
            // Invoice di atas tag, ukuran sama; tag tetap dicetak apa adanya.
            ->assertSeeInOrder(['INV : INV/2026/01/0001', 'TAG # : 2'])
            ->assertDontSee('TAG # : INV/2026/01/0001');
    }

    /**
     * Berat packing tidak seragam: label mengelompokkan per berat, bukan satu
     * baris per packing. Nomor packing tetap tidak dicetak, dan label tidak
     * memanjang mengikuti jumlah packing.
     */
    public function test_label_groups_packings_by_weight_when_weights_differ(): void
    {
        [$arrival, $item] = $this->createArrivalWithItem('KGM');

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 9001 GCI',
            'location_code' => 'BULK-AREA',
            'tags' => [
                [
                    'tag' => 'TAG-MIX-01',
                    'qty' => 3000,
                    'qty_unit' => 'KGM',
                    'bundle_count' => 1,
                    'bundle_unit' => 'PALLET',
                    'packing_count' => 4,
                    'packing_type' => 'COIL',
                    'packing_qty' => 750,
                    'net_weight' => 3000,
                    'packings' => [
                        ['packing_no' => 'TAG-MIX-01/1', 'sequence' => 1, 'qty' => 1000, 'uom' => 'KGM', 'net_weight' => 1000],
                        ['packing_no' => 'TAG-MIX-01/2', 'sequence' => 2, 'qty' => 500, 'uom' => 'KGM', 'net_weight' => 500],
                        ['packing_no' => 'TAG-MIX-01/3', 'sequence' => 3, 'qty' => 500, 'uom' => 'KGM', 'net_weight' => 500],
                        ['packing_no' => 'TAG-MIX-01/4', 'sequence' => 4, 'qty' => 1000, 'uom' => 'KGM', 'net_weight' => 1000],
                    ],
                ],
            ],
        ])->assertRedirect();

        $receive = IncomingReceive::where('tag', 'TAG-MIX-01')->firstOrFail();

        $this->get(route('receive.label', $receive))
            ->assertOk()
            ->assertSee('1 PALLET')
            ->assertSee('3,000 KGM')
            ->assertSee('4 COIL')
            ->assertSee('2 × 1,000 KGM')
            ->assertSee('2 × 500 KGM')
            ->assertDontSee('4 COIL @')
            ->assertDontSee('TAG-MIX-01/1')
            ->assertDontSee('TAG-MIX-01/2');
    }

    public function test_receive_show_uses_the_supplier_material_group_not_the_part_number(): void
    {
        $mapping = PartSubstitute::query()
            ->where('is_active', true)
            ->whereNotNull('supplier_id')
            ->whereNotNull('material_group')
            ->whereRaw("TRIM(material_group) <> ''")
            ->with('substitutePart')
            ->firstOrFail();

        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-GRP-'.uniqid(),
            'supplier_id' => $mapping->supplier_id,
            'is_local' => false,
            'status' => 'pending',
            'iqc_status' => 'passed',
        ]);

        // Data lama: kolom material_group berisi part number.
        IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $mapping->substitute_part_id,
            'material_group' => $mapping->substitutePart->part_number,
            'size' => '1.2MM',
            'qty_goods' => 100,
            'unit_goods' => 'KGM',
            'weight_nett' => 100,
            'unit_weight' => 'KGM',
        ]);

        $this->get(route('receive.show', $arrival))
            ->assertOk()
            ->assertSee($mapping->material_group)
            ->assertDontSee($mapping->substitutePart->part_number.' · 1.2MM');
    }

    public function test_receive_form_shows_the_supplier_material_group(): void
    {
        $mapping = PartSubstitute::query()
            ->where('is_active', true)
            ->whereNotNull('supplier_id')
            ->whereNotNull('material_group')
            ->whereRaw("TRIM(material_group) <> ''")
            ->with('substitutePart')
            ->firstOrFail();

        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-GRPFORM-'.uniqid(),
            'supplier_id' => $mapping->supplier_id,
            'is_local' => false,
            'status' => 'pending',
            'iqc_status' => 'passed',
        ]);

        // Data lama: kolom material_group berisi part number.
        $item = IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $mapping->substitute_part_id,
            'material_group' => $mapping->substitutePart->part_number,
            'size' => '1.2MM',
            'qty_goods' => 100,
            'unit_goods' => 'KGM',
            'weight_nett' => 100,
            'unit_weight' => 'KGM',
        ]);

        $this->get(route('receive.create', $item))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Incoming/Receive/Form')
                ->where('arrivalItem.material_group_label', $mapping->material_group));
    }
}
