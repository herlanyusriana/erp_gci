<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Location;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\PickingList;
use App\Models\Trolley;
use App\Models\User;
use App\Services\DeliveryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DeliveryQcApiTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Customer $customer;

    private Part $fg;

    private Location $origin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);

        $this->customer = Customer::create([
            'customer_code' => 'API-QC-'.uniqid(),
            'customer_name' => 'Customer QC API',
            'is_active' => true,
        ]);

        $this->fg = Part::where('part_number', 'AAN30056405')->firstOrFail();

        Trolley::query()->delete();
        Trolley::create(['code' => 'TRL-1', 'type' => 'internal', 'kind' => 'compbase']);

        $this->origin = Location::firstOrCreate(
            ['code' => 'TPL_DONGSHIN'],
            ['name' => 'TPL DONGSHIN', 'type' => Location::TYPE_MACHINE, 'is_active' => true],
        );
        $this->origin->update(['type' => Location::TYPE_MACHINE, 'is_active' => true]);

        Location::firstOrCreate(
            ['code' => 'DELIVERY-PREP'],
            ['name' => 'Delivery Preparation Area', 'type' => Location::TYPE_DELIVERY_PREP, 'is_active' => true],
        )->update(['type' => Location::TYPE_DELIVERY_PREP, 'is_active' => true]);
    }

    /** Dokumen yang sudah dipindah ke area pengiriman (status `picked`). */
    private function pickedDelivery(string $tag): Delivery
    {
        $stock = PartStock::create([
            'part_id' => $this->fg->id,
            'tag' => $tag,
            'qty' => 10,
            'qty_unit' => 'PCS',
            'location_code' => $this->origin->code,
            'received_at' => now(),
        ]);

        $this->post(route('picking-lists.store'), [
            'picking_date' => now()->toDateString(),
            'items' => [[
                'sequence' => 1,
                'customer_id' => $this->customer->id,
                'part_id' => $this->fg->id,
                'qty' => 10,
                'qty_unit' => 'PCS',
            ]],
        ])->assertRedirect();

        $list = PickingList::latest('id')->firstOrFail();
        $this->post(route('picking-lists.deliveries.generate', $list))->assertRedirect();
        $delivery = Delivery::where('picking_list_id', $list->id)->firstOrFail();

        $this->postJson("/api/deliveries/{$delivery->id}/scan", ['code' => 'TRL-1'])->assertOk();
        $this->postJson("/api/deliveries/{$delivery->id}/scan", [
            'code' => (string) $stock->tag,
            'qty' => 10,
        ])->assertOk();

        $delivery->refresh();
        $this->assertSame(Delivery::STATUS_PICKED, $delivery->status);

        return $delivery;
    }

    private function markTrolley(Delivery $delivery, string $status): void
    {
        $this->postJson("/api/deliveries/{$delivery->id}/trolley-qc", [
            'trolley_code' => 'TRL-1',
            'status' => $status,
        ])->assertOk();
    }

    public function test_qc_role_can_pass_oqc_from_the_api(): void
    {
        $qc = User::where('email', 'qc@geumcheon.local')->firstOrFail();
        $delivery = $this->pickedDelivery('TAG-QCAPI-PASS-'.uniqid());
        $this->markTrolley($delivery, 'ok');

        $this->actingAs($qc)
            ->postJson(route('api.deliveries.pass-qc', $delivery), ['qc_notes' => 'Sesuai spesifikasi'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.status', Delivery::STATUS_QC_PASSED)
            ->assertJsonPath('data.qc_status', Delivery::QC_PASSED);

        $fresh = $delivery->fresh();
        $this->assertSame(Delivery::STATUS_QC_PASSED, $fresh->status);
        $this->assertSame($qc->id, (int) $fresh->qc_checked_by);
        $this->assertSame('Sesuai spesifikasi', $fresh->qc_notes);
    }

    public function test_qc_role_can_reject_oqc_from_the_api(): void
    {
        $qc = User::where('email', 'qc@geumcheon.local')->firstOrFail();
        $delivery = $this->pickedDelivery('TAG-QCAPI-REJ-'.uniqid());

        $this->actingAs($qc)
            ->postJson(route('api.deliveries.reject-qc', $delivery), ['qc_notes' => 'Kemasan rusak'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.status', Delivery::STATUS_DRAFT)
            ->assertJsonPath('data.qc_status', Delivery::QC_REJECTED);

        $fresh = $delivery->fresh();
        $this->assertSame(Delivery::QC_REJECTED, $fresh->qc_status);
        $this->assertSame('Kemasan rusak', $fresh->qc_notes);
        $this->assertNull($fresh->picked_at);
    }

    public function test_rejection_requires_a_reason(): void
    {
        $delivery = $this->pickedDelivery('TAG-QCAPI-NOREASON-'.uniqid());

        $this->postJson(route('api.deliveries.reject-qc', $delivery), ['qc_notes' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('qc_notes');

        $this->assertSame(Delivery::STATUS_PICKED, $delivery->fresh()->status);
    }

    public function test_role_without_oqc_permission_is_forbidden(): void
    {
        $outsider = User::where('email', 'production@geumcheon.local')->firstOrFail();
        $delivery = $this->pickedDelivery('TAG-QCAPI-FORBID-'.uniqid());

        $this->actingAs($outsider)
            ->postJson(route('api.deliveries.pass-qc', $delivery))
            ->assertForbidden();

        $this->actingAs($outsider)
            ->postJson(route('api.deliveries.reject-qc', $delivery), ['qc_notes' => 'Coba tolak'])
            ->assertForbidden();

        $this->assertSame(Delivery::STATUS_PICKED, $delivery->fresh()->status);
    }

    public function test_pass_before_the_goods_move_returns_422(): void
    {
        $delivery = $this->pickedDelivery('TAG-QCAPI-EARLY-'.uniqid());
        app(DeliveryService::class)->returnToOrigin($delivery->fresh());

        $this->postJson(route('api.deliveries.pass-qc', $delivery->fresh()))
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('errors.status.0', __('OQC hanya bisa dilakukan setelah barang berada di area pengiriman.'));
    }
}
