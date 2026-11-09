<?php

namespace Tests\Feature;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalContainer;
use App\Models\Part;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ArrivalContainerInputTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);
    }

    private function payload(array $overrides = []): array
    {
        $supplier = Supplier::where('is_active', true)->firstOrFail();
        $part = Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))->firstOrFail();

        return array_merge([
            'is_local' => false,
            'supplier_id' => $supplier->id,
            'invoice_no' => 'INV-CONT-'.uniqid(),
            'invoice_date' => now()->toDateString(),
            'items' => [[
                'part_id' => $part->id,
                'qty_goods' => 100,
                'unit_goods' => 'KGM',
                'weight_nett' => 100,
            ]],
            'containers' => [
                ['container_no' => 'MSCU1234567', 'seal_code' => 'SEAL-001', 'size' => '40 FT'],
                ['container_no' => 'TGHU7654321', 'seal_code' => 'SEAL-002', 'size' => '20 FT'],
            ],
        ], $overrides);
    }

    public function test_import_arrival_stores_containers_and_seals(): void
    {
        $this->post(route('incoming-arrivals.store'), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $arrival = IncomingArrival::where('invoice_no', 'like', 'INV-CONT-%')->latest('id')->firstOrFail();

        $this->assertSame(2, $arrival->containers()->count());
        $this->assertDatabaseHas('incoming_arrival_containers', [
            'arrival_id' => $arrival->id,
            'container_no' => 'MSCU1234567',
            'seal_code' => 'SEAL-001',
            'size' => '40 FT',
        ]);
    }

    public function test_local_arrival_ignores_containers(): void
    {
        $this->post(route('incoming-arrivals.store'), $this->payload([
            'is_local' => true,
            'delivery_note_no' => 'SJ-CONT-'.uniqid(),
            'pen_no' => 'PEN-1',
            'aju_no' => 'AJU-1',
        ]))->assertSessionHasNoErrors();

        $arrival = IncomingArrival::where('is_local', true)->latest('id')->firstOrFail();

        $this->assertSame(0, $arrival->containers()->count());
    }

    public function test_container_without_number_is_rejected(): void
    {
        $this->post(route('incoming-arrivals.store'), $this->payload([
            'containers' => [['container_no' => '', 'seal_code' => 'SEAL-X']],
        ]))->assertSessionHasErrors('containers.0.container_no');
    }

    public function test_update_replaces_containers_and_clears_them_for_local(): void
    {
        $this->post(route('incoming-arrivals.store'), $this->payload())->assertSessionHasNoErrors();
        $arrival = IncomingArrival::where('invoice_no', 'like', 'INV-CONT-%')->latest('id')->firstOrFail();

        $this->assertSame(2, $arrival->containers()->count());

        // Update: hanya satu container tersisa
        $this->put(route('incoming-arrivals.update', $arrival), $this->payload([
            'invoice_no' => $arrival->invoice_no,
            'status' => 'pending',
            'containers' => [
                ['container_no' => 'ONLYONE0001', 'seal_code' => 'SEAL-ONLY', 'size' => '40 HC'],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, $arrival->fresh()->containers()->count());
        $this->assertDatabaseHas('incoming_arrival_containers', ['container_no' => 'ONLYONE0001']);

        // Beralih ke lokal: seluruh container dibersihkan
        $this->put(route('incoming-arrivals.update', $arrival), $this->payload([
            'is_local' => true,
            'invoice_no' => $arrival->invoice_no,
            'status' => 'pending',
            'delivery_note_no' => 'SJ-SWITCH-1',
            'pen_no' => 'PEN-2',
            'aju_no' => 'AJU-2',
            'containers' => [],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(0, IncomingArrivalContainer::where('arrival_id', $arrival->id)->count());
    }
}
