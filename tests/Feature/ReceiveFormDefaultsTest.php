<?php

namespace Tests\Feature;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\Part;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReceiveFormDefaultsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());
    }

    public function test_receive_form_uses_remaining_arrival_qty_and_arrival_unit_as_defaults(): void
    {
        $part = Part::where('part_number', 'AAN30056405')->firstOrFail();
        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-RECEIVE-'.uniqid(),
            'supplier_id' => Supplier::query()->value('id'),
            'is_local' => true,
            'status' => 'pending',
            'iqc_status' => 'passed',
        ]);
        $item = IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $part->id,
            'qty_goods' => 100,
            'unit_goods' => 'SHEET',
            'qty_bundle' => 4,
            'unit_bundle' => 'PALLET',
            'weight_nett' => null,
        ]);

        $this->get(route('receive.create', $item))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Incoming/Receive/Form')
                ->where('arrivalItem.unit_goods', 'SHEET')
                ->where('remainingQty', 100)
                ->where('arrivalItem.qty_bundle', 4)
                ->where('arrivalItem.unit_bundle', 'PALLET'));
    }
}
