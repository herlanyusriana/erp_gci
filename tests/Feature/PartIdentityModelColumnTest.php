<?php

namespace Tests\Feature;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\PartSubstitute;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Aturan repo: setiap list yang menampilkan Part No / Part Name wajib punya
 * kolom Model. Nomor dan nama part tidak unik antar model, jadi tanpa model
 * operator bisa membaca atau memilih part yang salah.
 */
class PartIdentityModelColumnTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function incomingListPages(): array
    {
        return [
            'arrival show' => ['Incoming/Arrival/Show.vue'],
            'local po show' => ['Incoming/LocalPo/Show.vue'],
            'purchase order show' => ['Incoming/PurchaseOrder/Show.vue'],
            'receive show' => ['Incoming/Receive/Show.vue'],
            'stock index' => ['Incoming/Stock/Index.vue'],
        ];
    }

    #[DataProvider('incomingListPages')]
    public function test_incoming_list_renders_a_model_column(string $page): void
    {
        $source = file_get_contents(resource_path('js/Pages/'.$page));
        $this->assertNotFalse($source, "Halaman {$page} tidak ditemukan.");

        $this->assertStringContainsString(
            "{{ t('master.model') }}",
            $source,
            "Halaman {$page} menampilkan part tanpa header kolom Model.",
        );

        $this->assertMatchesRegularExpression(
            '/part\?\.model/',
            $source,
            "Halaman {$page} punya header Model tetapi tidak merender nilai part.model.",
        );
    }

    public function test_arrival_material_picker_payload_carries_the_part_model(): void
    {
        $part = Part::where('part_number', 'AAN30056405')->firstOrFail();
        $part->update(['model' => 'MODEL-ARRIVAL-1']);

        $supplier = Supplier::where('is_active', true)->firstOrFail();

        PartSubstitute::query()
            ->where('supplier_id', $supplier->id)
            ->where('substitute_part_id', $part->id)
            ->delete();

        PartSubstitute::create([
            'supplier_id' => $supplier->id,
            'part_id' => $part->id,
            'substitute_part_id' => $part->id,
            'material_group' => 'GROUP-MODEL-1',
            'size' => 'SIZE-MODEL-1',
            'is_active' => true,
        ]);

        $this->get(route('incoming-arrivals.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incoming/Arrival/Form')
                ->where('materialOptions', function ($options) use ($part) {
                    $row = collect($options)->firstWhere('part_id', $part->id);

                    return $row !== null && ($row['model'] ?? null) === 'MODEL-ARRIVAL-1';
                }));
    }

    public function test_part_select_renders_model_beside_the_part_name(): void
    {
        $source = file_get_contents(resource_path('js/Components/PartSelect.vue'));
        $this->assertNotFalse($source);

        $this->assertStringContainsString('option.model', $source, 'PartSelect wajib merender model part.');
        $this->assertStringContainsString('sublabel-key="sublabel"', $source);
    }

    public function test_stock_index_serializes_the_part_model_from_master(): void
    {
        $part = Part::where('part_number', 'AAN30056405')->firstOrFail();
        $part->update(['model' => 'MODEL-STOCK-1']);

        $supplier = Supplier::create([
            'supplier_code' => 'SUP-MODEL-1',
            'supplier_name' => 'PT Model Test',
            'address' => 'Jl. Uji No. 1',
            'phone' => '021-0000001',
            'email' => 'model@test.local',
            'is_active' => true,
        ]);

        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-MODEL-1',
            'supplier_id' => $supplier->id,
            'is_local' => false,
            'status' => 'draft',
        ]);

        IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $part->id,
            'qty_goods' => 10,
            'unit_goods' => 'PCS',
        ]);

        $stock = PartStock::create([
            'part_id' => $part->id,
            'tag' => 'TAG-MODEL-1',
            'qty' => 10,
            'qty_unit' => 'PCS',
            'received_at' => now(),
        ]);

        $this->assertSame('TAG-MODEL-1', $stock->tag);

        $this->get(route('stocks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incoming/Stock/Index')
                ->where('stocks.data.0.part.part_number', 'AAN30056405')
                ->where('stocks.data.0.part.model', 'MODEL-STOCK-1'));
    }
}
