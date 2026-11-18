<?php

namespace Tests\Feature;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\IncomingReceive;
use App\Models\Part;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArrivalDeleteGuardTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());
    }

    private function makeArrival(bool $local = false): IncomingArrival
    {
        $attributes = [
            'arrival_no' => 'ARV-DEL-'.Str::upper(Str::random(6)),
            'supplier_id' => Supplier::query()->value('id'),
            'is_local' => $local,
            'status' => 'pending',
        ];

        if ($local) {
            $attributes['po_no'] = 'LPO-DEL-'.Str::upper(Str::random(6));
        }

        return IncomingArrival::create($attributes);
    }

    private function addItem(IncomingArrival $arrival): IncomingArrivalItem
    {
        return IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => Part::where('part_number', 'AAN30056405')->value('id'),
            'qty_goods' => 10,
            'unit_goods' => 'KGM',
            'weight_nett' => 10,
        ]);
    }

    private function addReceive(IncomingArrivalItem $item): IncomingReceive
    {
        return IncomingReceive::create([
            'arrival_item_id' => $item->id,
            'part_id' => $item->part_id,
            'tag' => 'T-DEL-'.Str::upper(Str::random(8)),
            'qty' => 10,
            'qty_unit' => 'KGM',
        ]);
    }

    public function test_deleting_an_arrival_with_receives_is_refused(): void
    {
        $arrival = $this->makeArrival();
        $this->addReceive($this->addItem($arrival));

        $this->delete(route('incoming-arrivals.destroy', $arrival))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted('incoming_arrivals', ['id' => $arrival->id]);
    }

    public function test_deleting_a_local_po_with_receives_is_refused(): void
    {
        $arrival = $this->makeArrival(local: true);
        $this->addReceive($this->addItem($arrival));

        $this->delete(route('local-pos.destroy', $arrival))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted('incoming_arrivals', ['id' => $arrival->id]);
    }

    public function test_deleting_an_arrival_without_receives_still_works(): void
    {
        $arrival = $this->makeArrival();
        $this->addItem($arrival);

        $this->delete(route('incoming-arrivals.destroy', $arrival))
            ->assertRedirect(route('incoming-arrivals.index'))
            ->assertSessionHas('success');

        $this->assertSoftDeleted('incoming_arrivals', ['id' => $arrival->id]);
    }
}
