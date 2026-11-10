<?php

namespace Tests\Feature;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\IncomingReceive;
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
            ->assertSee('1 PALLET @ 5 COIL @ 500 KGM');
    }

    public function test_receive_generates_auto_tag_when_identification_is_blank(): void
    {
        [$arrival, $item] = $this->createArrivalWithItem('EA');

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
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
        $this->assertMatchesRegularExpression('/^AUTO-\d{6}-\d{4}$/', (string) $receive->tag);
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

        // Label memuat baris ringkasan kemasan bertingkat
        $response->assertSee('2 PALLET @ 5 BOX @ 2,000 EA');
    }
}
