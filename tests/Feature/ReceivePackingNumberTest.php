<?php

namespace Tests\Feature;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\IncomingReceive;
use App\Models\Part;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReceivePackingNumberTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());
    }

    private function arrivalItem(int $qtyGoods = 500): IncomingArrivalItem
    {
        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-PACKING-'.uniqid(),
            'supplier_id' => Supplier::query()->value('id'),
            'is_local' => true,
            'status' => 'pending',
            'iqc_status' => 'passed',
        ]);

        return IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => Part::where('part_number', 'AAN30056405')->firstOrFail()->id,
            'qty_goods' => $qtyGoods,
            'unit_goods' => 'PCS',
            'qty_bundle' => null,
            'unit_bundle' => null,
            'weight_nett' => null,
        ]);
    }

    public function test_receive_stores_packing_numbers_beyond_two_digits(): void
    {
        $item = $this->arrivalItem(500);
        $tag = 'PACK-3DIGIT-'.strtoupper(uniqid());
        $qtyPerPacking = 4;

        $packings = collect(range(1, 120))->map(fn (int $seq) => [
            'packing_no' => $tag.'-'.$seq,
            'sequence' => $seq,
            'qty' => $qtyPerPacking,
            'uom' => 'PCS',
        ])->all();

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 9012 GCI',
            'location_code' => 'BULK-AREA',
            'tags' => [[
                'tag' => $tag,
                'qty' => $qtyPerPacking * 120,
                'qty_unit' => 'PCS',
                'bundle_count' => 1,
                'bundle_qty' => 1,
                'bundle_unit' => 'BUNDLE',
                'packing_count' => 120,
                'packing_type' => 'BOX',
                'packing_qty' => $qtyPerPacking,
                'packings' => $packings,
            ]],
        ])->assertRedirect();

        $receive = IncomingReceive::where('tag', $tag)->firstOrFail();

        $this->assertSame(120, $receive->packings()->count());
        $this->assertSame(120, (int) $receive->packings()->max('sequence'));
        $this->assertDatabaseHas('incoming_receive_packings', [
            'receive_id' => $receive->id,
            'packing_no' => $tag.'-120',
            'sequence' => 120,
        ]);

        // Ketika tag diedit, semua packing_no otomatis mengikuti {TAG_BARU}-{sequence}.
        $newTag = $tag.'-EDIT';
        $this->put(route('receive.update', $receive), [
            'receive_date' => now()->toDateString(),
            'tag' => $newTag,
            'truck_no' => 'B 9012 GCI',
            'location_code' => 'BULK-AREA',
            'qty' => $qtyPerPacking * 120,
            'bundle_count' => 1,
            'bundle_qty' => 1,
            'bundle_unit' => 'BUNDLE',
            'packing_count' => 120,
            'packing_type' => 'BOX',
            'packing_qty' => $qtyPerPacking,
        ])->assertRedirect();

        $this->assertDatabaseHas('incoming_receive_packings', [
            'receive_id' => $receive->id,
            'packing_no' => $newTag.'-1',
            'sequence' => 1,
        ]);
        $this->assertDatabaseHas('incoming_receive_packings', [
            'receive_id' => $receive->id,
            'packing_no' => $newTag.'-120',
            'sequence' => 120,
        ]);
    }

    public function test_receive_form_allows_three_digit_packing_count(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Incoming/Receive/Form.vue'));
        $this->assertNotFalse($source);

        $this->assertStringContainsString(
            'const MAX_PACKINGS = 999;',
            $source,
            'Batas packing di form harus 3 digit (999), bukan 30.',
        );
        $this->assertStringContainsString(':max="MAX_PACKINGS"', $source);
        $this->assertStringContainsString('incoming.packingRowLimit', $source);
        $this->assertStringNotContainsString('Math.min(30', $source, 'Batas lama 30 packing masih tersisa.');
    }

    public function test_receive_form_no_longer_asks_for_gross_weight(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Incoming/Receive/Form.vue'));
        $this->assertNotFalse($source);

        $this->assertStringNotContainsString('Gross weight', $source);
        $this->assertStringNotContainsString('r.gross_weight', $source);
        $this->assertStringNotContainsString('totals.gross', $source);
    }

    public function test_receive_edit_hides_gross_weight_but_keeps_stored_value(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Incoming/Receive/Edit.vue'));
        $this->assertNotFalse($source);

        $this->assertStringNotContainsString("t('incoming.grossWeight')", $source);
        $this->assertStringContainsString(
            'gross_weight: props.receive.gross_weight',
            $source,
            'Edit harus tetap mengirim gross_weight lama agar update tidak menimpanya dengan null.',
        );
    }

    public function test_receive_still_stores_gross_weight_when_provided(): void
    {
        $item = $this->arrivalItem(50);
        $tag = 'PACK-GROSS-'.strtoupper(uniqid());

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 9013 GCI',
            'location_code' => 'BULK-AREA',
            'tags' => [[
                'tag' => $tag,
                'qty' => 50,
                'qty_unit' => 'PCS',
                'net_weight' => 40,
                'gross_weight' => 45,
            ]],
        ])->assertRedirect();

        $this->assertDatabaseHas('incoming_receives', [
            'tag' => $tag,
            'net_weight' => '40.0000',
            'gross_weight' => '45.0000',
        ]);
    }
}
