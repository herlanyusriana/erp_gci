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
            'pen_no' => 'PEN-FORM-1',
            'aju_no' => 'AJU-FORM-1',
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
                ->where('arrivalItem.unit_bundle', 'PALLET')
                ->where('arrivalItem.arrival.pen_no', 'PEN-FORM-1')
                ->where('arrivalItem.arrival.aju_no', 'AJU-FORM-1')
                ->where('packingTypes', fn ($types) => collect($types)->contains('COIL')));
    }

    /**
     * Form penerimaan selalu menyediakan beberapa baris bundle kosong (default
     * 4 bundle x 3 packing). Server menolak tag ber-qty 0, jadi baris kosong
     * harus disaring sebelum dikirim — kalau tidak, klik Simpan tidak
     * menghasilkan apa pun dan stok tidak pernah masuk.
     */
    public function test_receive_form_filters_empty_bundles_before_submitting(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Incoming/Receive/Form.vue'));
        $this->assertNotFalse($source);

        $this->assertStringContainsString(
            'const filled = bundles.value.filter',
            $source,
            'Form harus menyaring bundle tanpa qty sebelum membentuk payload.',
        );
        $this->assertStringContainsString(
            'form.tags = filled.map',
            $source,
            'Payload tags harus dibentuk dari bundle yang terisi saja.',
        );
    }

    /**
     * Error validasi per baris (`tags.<n>.<field>`) tidak dirender di dalam
     * tabel, jadi harus ada ringkasan — tanpa itu penolakan server tidak
     * terlihat sama sekali.
     */
    public function test_receive_form_surfaces_per_row_server_errors(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Incoming/Receive/Form.vue'));
        $this->assertNotFalse($source);

        $this->assertStringContainsString('const rowErrors = computed', $source);
        $this->assertStringContainsString('v-if="rowErrors.length > 0"', $source);
        $this->assertStringContainsString('role="alert"', $source);
    }

    /**
     * Skenario nyata: item tanpa `qty_bundle` (form default 4 bundle) dan
     * pengguna hanya mengisi satu tag. Penerimaan harus tersimpan dan stok
     * terposting sebesar qty tag itu.
     */
    public function test_receiving_a_single_filled_bundle_saves_and_posts_stock(): void
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
            'unit_goods' => 'PCS',
            'qty_bundle' => null,
            'unit_bundle' => null,
            'weight_nett' => null,
        ]);

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 1234 GCI',
            'location_code' => 'BULK-AREA',
            'tags' => [[
                'tag' => 'RECV-ONE-'.uniqid(),
                'qty' => 100,
                'qty_unit' => 'PCS',
                'bundle_count' => 1,
                'bundle_qty' => 1,
                'bundle_unit' => 'BUNDLE',
                'packing_count' => 1,
                'packing_type' => 'BOX',
                'packing_qty' => 100,
                'net_weight' => null,
                'gross_weight' => null,
            ]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, $item->receives()->count(), 'Penerimaan harus tersimpan.');
        $this->assertDatabaseHas('part_stocks', [
            'part_id' => $part->id,
            'qty' => 100,
        ]);
    }
}
