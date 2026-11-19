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
            'invoice_no' => 'INV-FORM-1',
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
                ->where('remainingGoodsQty', 100)
                ->where('remainingNetWeight', 0)
                ->where('arrivalItem.qty_bundle', 4)
                ->where('arrivalItem.unit_bundle', 'PALLET')
                ->where('arrivalItem.arrival.invoice_no', 'INV-FORM-1')
                ->where('arrivalItem.arrival.pen_no', 'PEN-FORM-1')
                ->where('arrivalItem.arrival.aju_no', 'AJU-FORM-1')
                ->where('packingTypes', fn ($types) => collect($types)->contains('COIL')));
    }

    /**
     * Item berbasis berat: qty memakai satuan barang (mis. COIL), sedangkan
     * net weight selalu KGM. Form harus menerima sisa keduanya secara terpisah
     * supaya auto-distribute tidak mengisi net weight dengan angka qty
     * (825 COIL berubah menjadi 825 KGM).
     */
    public function test_receive_form_exposes_remaining_goods_qty_and_net_weight_separately(): void
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
            'qty_goods' => 250,
            'unit_goods' => 'COIL',
            'weight_nett' => 825,
            'unit_weight' => 'KGM',
        ]);

        $this->get(route('receive.create', $item))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Incoming/Receive/Form')
                ->where('weightBasis', true)
                ->where('remainingQty', 825)
                ->where('maxReceivable', 825)
                ->where('remainingGoodsQty', 250)
                ->where('remainingNetWeight', 825));
    }

    /**
     * Setelah sebagian diterima, sisa qty dan sisa net weight harus berkurang
     * sendiri-sendiri; qty 100 COIL tidak boleh mengurangi 100 KGM.
     */
    public function test_receive_form_remaining_tracks_partial_receives_in_both_units(): void
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
            'qty_goods' => 250,
            'unit_goods' => 'COIL',
            'weight_nett' => 825,
            'unit_weight' => 'KGM',
        ]);
        IncomingReceive::create([
            'arrival_item_id' => $item->id,
            'part_id' => $part->id,
            'tag' => 'TAG-PARTIAL',
            'qty' => 100,
            'qty_unit' => 'COIL',
            'weight' => 330,
            'net_weight' => 330,
            'qc_status' => 'pass',
        ]);

        $this->get(route('receive.create', $item))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Incoming/Receive/Form')
                ->where('remainingQty', 495)
                ->where('maxReceivable', 495)
                ->where('remainingGoodsQty', 150)
                ->where('remainingNetWeight', 495));
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
     * No. Faktur harus terlihat di form penerimaan. Label cetak memakai
     * invoice dari dokumen arrival, sedangkan `incoming_receives.invoice_no`
     * hanyalah snapshot — operator perlu melihat nomor yang sebenarnya
     * dipakai label sebelum menyimpan.
     */
    public function test_receive_form_shows_arrival_invoice_as_read_only(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Incoming/Receive/Form.vue'));
        $this->assertNotFalse($source);

        $this->assertStringContainsString("t('incoming.invoiceNo')", $source);
        $this->assertStringContainsString(":value=\"arrivalItem.arrival?.invoice_no || '—'\"", $source);
        $this->assertMatchesRegularExpression(
            '/:value="arrivalItem\.arrival\?\.invoice_no[^>]*\n\s*readonly/s',
            $source,
            'No. Faktur hanya informasi dari master arrival, bukan input.',
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

    /**
     * No. PEN diisi saat penerimaan, tetapi input-nya sempat hilang dari form
     * (hanya No. AJU yang dirender) sehingga nilainya tidak pernah bisa diisi.
     */
    public function test_receive_form_renders_pen_no_input(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Incoming/Receive/Form.vue'));
        $this->assertNotFalse($source);

        $this->assertStringContainsString('v-model="form.pen_no"', $source);
        $this->assertStringContainsString("t('incoming.penNo')", $source);
    }

    /**
     * Semua penolakan (validasi klien maupun server) harus tampil di dekat
     * tombol Simpan. Sebelumnya pesan validasi klien dirender di atas tabel —
     * di luar layar saat pengguna menekan Simpan — sehingga klik Simpan
     * terlihat "tidak terjadi apa-apa".
     */
    public function test_receive_form_renders_blocking_errors_next_to_submit_button(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Incoming/Receive/Form.vue'));
        $this->assertNotFalse($source);

        $alert = 'v-if="clientError || rowErrors.length > 0 || otherErrors.length > 0"';
        $this->assertStringContainsString($alert, $source, 'Alert harus merangkum error klien, error baris, dan error server.');
        $this->assertStringContainsString('otherErrors', $source, 'Error server non-tag harus ikut ditampilkan.');
        $this->assertStringNotContainsString('<p v-if="clientError"', $source, 'Pesan klien tidak boleh dirender jauh di atas tombol Simpan.');

        $alertPos = strpos($source, $alert);
        $submitPos = strpos($source, 'type="submit"');

        $this->assertNotFalse($alertPos);
        $this->assertNotFalse($submitPos);
        $this->assertLessThan($submitPos, $alertPos, 'Alert harus berada tepat sebelum tombol Simpan.');
    }

    /**
     * Atribut HTML `required` membuat browser memblokir submit sebelum handler
     * Vue berjalan. Tooltip validasi native muncul di input yang ada di atas
     * layar, sehingga tombol Simpan di bagian bawah terlihat "tidak terjadi
     * apa-apa". Validasi wajib tetap di JS supaya pesannya tampil di dekat
     * tombol Simpan.
     */
    public function test_receive_form_does_not_use_native_required_attribute(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Incoming/Receive/Form.vue'));
        $this->assertNotFalse($source);

        $this->assertDoesNotMatchRegularExpression(
            '/^\s+required\s*$/m',
            $source,
            'Atribut `required` memblokir submit tanpa pesan yang terlihat; pakai validasi JS.'
        );
    }

    /**
     * Item kedatangan boleh dibuat tanpa part master. Dulu `postStock` hanya
     * `return` untuk kasus itu: penerimaan tersimpan tetapi stok tidak pernah
     * dibuat. Sekarang harus ditolak dengan pesan yang terlihat.
     */
    public function test_receive_without_master_part_is_rejected_instead_of_silently_skipped(): void
    {
        $tag = 'NO-PART-'.uniqid();
        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-NOPART-'.uniqid(),
            'supplier_id' => Supplier::query()->value('id'),
            'is_local' => true,
            'status' => 'pending',
            'iqc_status' => 'passed',
        ]);
        $item = IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => null,
            'material_group' => 'STEEL PLATE',
            'qty_goods' => 10,
            'unit_goods' => 'KGM',
            'weight_nett' => 10,
        ]);

        $this->post(route('receive.store', $item), [
            'receive_date' => now()->toDateString(),
            'truck_no' => 'B 1234 GCI',
            'location_code' => 'BULK-AREA',
            'tags' => [[
                'tag' => $tag,
                'qty' => 10,
                'qty_unit' => 'KGM',
                'net_weight' => 10,
            ]],
        ])->assertSessionHasErrors('tags');

        $this->assertSame(0, $item->receives()->count(), 'Penerimaan harus dibatalkan, bukan tersimpan tanpa stok.');
        $this->assertDatabaseMissing('part_stocks', ['tag' => $tag]);
    }
}
