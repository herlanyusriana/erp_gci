<?php

namespace Tests\Feature;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ReceiveMaterialService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceiveStockUnitTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());
    }

    private function makeArrivalItem(string $unitGoods, float $weightNett): IncomingArrivalItem
    {
        $part = Part::where('part_number', 'AAN30056405')->firstOrFail();

        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-UNIT-'.Str::upper(Str::random(6)),
            'supplier_id' => Supplier::query()->value('id'),
            'is_local' => false,
            'status' => 'draft',
        ]);

        return IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $part->id,
            'qty_goods' => 1600,
            'unit_goods' => $unitGoods,
            'weight_nett' => $weightNett,
        ]);
    }

    public function test_receive_posts_stock_in_material_unit_not_weight(): void
    {
        $item = $this->makeArrivalItem('SHEET', 3500);

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'tags' => [[
                'tag' => 'T-SHEET-UNIT-1',
                'qty' => 800,
                'qty_unit' => 'SHEET',
                'net_weight' => 1750,
            ]],
        ])->assertRedirect();

        $stock = PartStock::where('tag', 'T-SHEET-UNIT-1')->firstOrFail();

        $this->assertSame('SHEET', $stock->qty_unit);
        $this->assertEqualsWithDelta(800.0, (float) $stock->qty, 0.001);

        // Stok kini cocok dengan uom_rm SHEET (dipakai badge, tag, dan release).
        $service = app(ReceiveMaterialService::class);
        $available = $service->availableFifoBatch([(int) $stock->part_id], 'SHEET');
        $this->assertEqualsWithDelta(800.0, $available[(int) $stock->part_id], 0.001);
    }

    public function test_receive_posts_weight_material_as_weight(): void
    {
        $item = $this->makeArrivalItem('KGM', 3500);

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'tags' => [[
                'tag' => 'T-KGM-UNIT-1',
                'qty' => 1868,
                'qty_unit' => 'KGM',
                'net_weight' => 1868,
            ]],
        ])->assertRedirect();

        $stock = PartStock::where('tag', 'T-KGM-UNIT-1')->firstOrFail();

        $this->assertSame('KGM', $stock->qty_unit);
        $this->assertEqualsWithDelta(1868.0, (float) $stock->qty, 0.001);
    }

    public function test_delete_receive_reverses_same_amount_as_posted(): void
    {
        $item = $this->makeArrivalItem('SHEET', 3500);

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'tags' => [[
                'tag' => 'T-SHEET-UNIT-2',
                'qty' => 400,
                'qty_unit' => 'SHEET',
                'net_weight' => 900,
            ]],
        ])->assertRedirect();

        $receive = $item->receives()->where('tag', 'T-SHEET-UNIT-2')->firstOrFail();
        $this->assertEqualsWithDelta(400.0, (float) PartStock::where('tag', 'T-SHEET-UNIT-2')->value('qty'), 0.001);

        $this->delete(route('receive.destroy', $receive))->assertRedirect();

        $this->assertSoftDeleted('part_stocks', ['tag' => 'T-SHEET-UNIT-2']);
    }
}
