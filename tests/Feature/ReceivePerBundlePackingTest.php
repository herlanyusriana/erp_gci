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

/**
 * Satu penerimaan boleh berisi bundle dengan jumlah packing berbeda.
 *
 * Di lapangan satu tag berisi 3 packing, tag berikutnya 8 packing. Tiap bundle
 * adalah satu baris `incoming_receives` dengan daftar packing sendiri, jadi
 * perbedaan itu memang didukung model data — yang diuji di sini adalah server
 * menerimanya dan menyimpan jumlah packing per bundle, bukan satu angka global.
 */
class ReceivePerBundlePackingTest extends TestCase
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
            'arrival_no' => 'ARV-BUNDLE-'.uniqid(),
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

    /**
     * @param  array<int, array{qty: float, net_weight?: float|null}>  $packings
     * @return array<string, mixed>
     */
    private function tagPayload(string $tag, array $packings, string $packingType = 'BOX'): array
    {
        $qty = array_sum(array_column($packings, 'qty'));

        return [
            'tag' => $tag,
            'qty' => $qty,
            'qty_unit' => 'PCS',
            'bundle_count' => 1,
            'bundle_qty' => 1,
            'bundle_unit' => 'BUNDLE',
            'packing_count' => count($packings),
            'packing_type' => $packingType,
            'packing_qty' => $qty / max(1, count($packings)),
            'packings' => collect($packings)->map(fn (array $p, int $i) => [
                'packing_no' => $tag.'/'.($i + 1),
                'sequence' => $i + 1,
                'qty' => $p['qty'],
                'uom' => 'PCS',
                'net_weight' => $p['net_weight'] ?? null,
            ])->all(),
        ];
    }

    public function test_receive_stores_different_packing_count_per_bundle(): void
    {
        $item = $this->arrivalItem(500);
        $suffix = strtoupper(uniqid());
        $firstTag = 'BUNDLE-3-'.$suffix;
        $secondTag = 'BUNDLE-8-'.$suffix;

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 9101 GCI',
            'location_code' => 'BULK-AREA',
            'tags' => [
                $this->tagPayload($firstTag, array_fill(0, 3, ['qty' => 10.0])),
                $this->tagPayload($secondTag, array_fill(0, 8, ['qty' => 5.0])),
            ],
        ])->assertSessionHasNoErrors();

        $first = IncomingReceive::where('tag', $firstTag)->firstOrFail();
        $second = IncomingReceive::where('tag', $secondTag)->firstOrFail();

        $this->assertSame(3, $first->packings()->count());
        $this->assertSame(8, $second->packings()->count());
        $this->assertSame(3.0, (float) $first->packing_count);
        $this->assertSame(8.0, (float) $second->packing_count);

        // Nomor packing mengikuti urutan per bundle, bukan menumpuk dari bundle pertama.
        $this->assertSame($secondTag.'/8', $second->packings()->orderByDesc('sequence')->value('packing_no'));
    }

    public function test_packing_type_is_stored_per_bundle(): void
    {
        $item = $this->arrivalItem(500);
        $suffix = strtoupper(uniqid());
        $coilTag = 'BUNDLE-COIL-'.$suffix;
        $boxTag = 'BUNDLE-BOX-'.$suffix;

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 9102 GCI',
            'location_code' => 'BULK-AREA',
            'tags' => [
                $this->tagPayload($coilTag, array_fill(0, 2, ['qty' => 10.0]), 'COIL'),
                $this->tagPayload($boxTag, array_fill(0, 4, ['qty' => 5.0]), 'BOX'),
            ],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('incoming_receives', [
            'tag' => $coilTag,
            'packing_type' => 'COIL',
            'packing_count' => 2,
        ]);
        $this->assertDatabaseHas('incoming_receives', [
            'tag' => $boxTag,
            'packing_type' => 'BOX',
            'packing_count' => 4,
        ]);
    }

    public function test_packing_row_without_qty_and_without_weight_is_rejected(): void
    {
        $item = $this->arrivalItem(500);
        $tag = 'BUNDLE-EMPTY-'.strtoupper(uniqid());

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 9103 GCI',
            'location_code' => 'BULK-AREA',
            'tags' => [
                $this->tagPayload($tag, [
                    ['qty' => 10.0],
                    ['qty' => 0.0],
                ]),
            ],
        ])->assertSessionHasErrors('tags.0.packings.1');

        $this->assertDatabaseMissing('incoming_receives', ['tag' => $tag]);
    }

    public function test_packing_row_with_only_net_weight_is_accepted(): void
    {
        $item = $this->arrivalItem(500);
        $tag = 'BUNDLE-WEIGHT-'.strtoupper(uniqid());

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 9104 GCI',
            'location_code' => 'BULK-AREA',
            'tags' => [
                $this->tagPayload($tag, [
                    ['qty' => 10.0, 'net_weight' => 10.0],
                    ['qty' => 0.0, 'net_weight' => 4.5],
                ]),
            ],
        ])->assertSessionHasNoErrors();

        $receive = IncomingReceive::where('tag', $tag)->firstOrFail();

        $this->assertSame(2, $receive->packings()->count());
        $this->assertDatabaseHas('incoming_receive_packings', [
            'receive_id' => $receive->id,
            'sequence' => 2,
            'qty' => 0,
            'net_weight' => 4.5,
        ]);
    }
}
