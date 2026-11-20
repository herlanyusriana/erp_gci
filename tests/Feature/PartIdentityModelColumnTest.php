<?php

namespace Tests\Feature;

use App\Models\Bom;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\IncomingReceive;
use App\Models\Machine;
use App\Models\MachineCycleTime;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueItem;
use App\Models\Part;
use App\Models\PartPrice;
use App\Models\PartStock;
use App\Models\PartSubstitute;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WorkOrder;
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

        $this->get(route('stocks.index', ['tab' => 'fg']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incoming/Stock/Index')
                ->where('stocks.data.0.part.part_number', 'AAN30056405')
                ->where('stocks.data.0.part.model', 'MODEL-STOCK-1'));
    }

    public function test_incoming_quality_serializes_part_model(): void
    {
        $part = Part::where('part_number', 'AAN30056405')->firstOrFail();
        $part->update(['model' => 'MODEL-IQC-1']);

        $supplier = Supplier::where('is_active', true)->firstOrFail();

        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-IQC-MODEL-1',
            'supplier_id' => $supplier->id,
            'is_local' => false,
            'status' => 'pending',
            'iqc_status' => 'pending',
        ]);

        IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $part->id,
            'qty_goods' => 5,
            'unit_goods' => 'PCS',
            'iqc_status' => 'pending',
        ]);

        $this->get(route('incoming-quality.index', ['status' => 'all', 'search' => 'ARV-IQC-MODEL-1']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Quality/Incoming/Index')
                ->where('arrivals.data.0.items.0.part.model', 'MODEL-IQC-1'));

        $this->get(route('incoming-quality.show', $arrival))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Quality/Incoming/Show')
                ->where('arrival.items.0.part.model', 'MODEL-IQC-1'));
    }

    public function test_cycle_times_index_serializes_part_model(): void
    {
        $part = Part::where('part_number', 'AAN30056405')->firstOrFail();
        $part->update(['model' => 'MODEL-CT-1']);

        $machine = Machine::where('is_active', true)->firstOrFail();

        MachineCycleTime::updateOrCreate(
            ['machine_id' => $machine->id, 'part_id' => $part->id],
            ['cycle_time_seconds' => 12.5, 'is_active' => true],
        );

        $this->get(route('cycle-times.index', ['search' => $part->part_number]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/CycleTime/Index')
                ->where('cycleTimes.data', function ($rows) use ($part) {
                    $row = collect($rows)->firstWhere('part_id', $part->id);

                    return $row !== null && ($row['part']['model'] ?? null) === 'MODEL-CT-1';
                })
                ->where('parts', function ($parts) use ($part) {
                    $row = collect($parts)->firstWhere('id', $part->id);

                    return $row !== null && ($row['model'] ?? null) === 'MODEL-CT-1';
                }));
    }

    public function test_prices_index_serializes_part_model(): void
    {
        $part = Part::where('part_number', 'AAN30056405')->firstOrFail();
        $part->update(['model' => 'MODEL-PRICE-1']);

        $supplier = Supplier::where('is_active', true)->firstOrFail();

        PartPrice::create([
            'supplier_id' => $supplier->id,
            'part_id' => $part->id,
            'price' => 1500,
            'currency' => 'IDR',
            'valid_from' => '2099-01-01',
            'is_active' => true,
        ]);

        $this->get(route('prices.index', ['search' => $part->part_number]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/Price/Index')
                ->where('prices.data', function ($rows) use ($part) {
                    $row = collect($rows)->firstWhere('part_id', $part->id);

                    return $row !== null && ($row['part']['model'] ?? null) === 'MODEL-PRICE-1';
                })
                ->where('parts', function ($parts) use ($part) {
                    $row = collect($parts)->firstWhere('id', $part->id);

                    return $row !== null && ($row['model'] ?? null) === 'MODEL-PRICE-1';
                }));
    }

    public function test_locations_setup_serializes_part_model(): void
    {
        $part = Part::where('part_number', 'AAN30056405')->firstOrFail();
        $part->update(['model' => 'MODEL-LOC-1']);

        $supplier = Supplier::where('is_active', true)->firstOrFail();
        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-LOC-MODEL-1',
            'supplier_id' => $supplier->id,
            'is_local' => false,
            'status' => 'pending',
        ]);
        $arrivalItem = IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $part->id,
            'qty_goods' => 10,
            'unit_goods' => 'PCS',
        ]);

        IncomingReceive::create([
            'arrival_item_id' => $arrivalItem->id,
            'part_id' => $part->id,
            'tag' => 'TAG-LOC-MODEL-1',
            'qty' => 10,
            'qty_unit' => 'PCS',
            'location_code' => null,
            'qc_status' => 'ok',
        ]);

        $this->get(route('locations.setup'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/Location/Setup')
                ->where('receives.data', function ($rows) {
                    $row = collect($rows)->firstWhere('tag', 'TAG-LOC-MODEL-1');

                    return $row !== null && ($row['part']['model'] ?? null) === 'MODEL-LOC-1';
                }));
    }

    public function test_bom_show_serializes_part_model(): void
    {
        $bom = Bom::query()->with(['part', 'items.childPart'])->whereHas('items')->firstOrFail();
        $bom->part->update(['model' => 'MODEL-BOM-FG-1']);
        $firstChild = $bom->items->first()?->childPart;
        $this->assertNotNull($firstChild);
        $firstChild->update(['model' => 'MODEL-BOM-CHILD-1']);

        $this->get(route('boms.show', $bom))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/Bom/Show')
                ->where('bom.part.model', 'MODEL-BOM-FG-1')
                ->where('bom.items', function ($items) use ($firstChild) {
                    $row = collect($items)->firstWhere('child_part_id', $firstChild->id);

                    return $row !== null && ($row['child_part']['model'] ?? null) === 'MODEL-BOM-CHILD-1';
                }));
    }

    public function test_delivery_show_serializes_part_model(): void
    {
        $part = Part::where('part_number', 'AAN30056405')->firstOrFail();
        $part->update(['model' => 'MODEL-DEL-1']);

        $customer = Customer::where('is_active', true)->firstOrFail();
        $delivery = Delivery::create([
            'delivery_no' => 'DO-MODEL-TEST-1',
            'customer_id' => $customer->id,
            'delivery_date' => now()->toDateString(),
            'status' => Delivery::STATUS_DRAFT,
        ]);

        DeliveryItem::create([
            'delivery_id' => $delivery->id,
            'part_id' => $part->id,
            'qty' => 5,
            'qty_unit' => 'PCS',
            'sequence' => 1,
            'is_picked' => false,
        ]);

        $this->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Outgoing/Delivery/Show')
                ->where('delivery.items.0.part.model', 'MODEL-DEL-1'));
    }

    public function test_material_issues_index_serializes_part_model(): void
    {
        $fgPart = Part::where('part_number', 'AAN30056405')->firstOrFail();
        $fgPart->update(['model' => 'MODEL-MI-FG-1']);

        $rmPart = Part::where('part_number', 'CBKG07256C')->firstOrFail();
        $rmPart->update(['model' => 'MODEL-MI-RM-1']);

        $wo = WorkOrder::create([
            'wo_no' => 'WO-MI-MODEL-1',
            'part_id' => $fgPart->id,
            'qty' => 10,
            'status' => 'in_progress',
            'planned_date' => now()->toDateString(),
        ]);

        $woItem = $wo->items()->create([
            'parent_part_id' => $fgPart->id,
            'child_part_id' => $rmPart->id,
            'selected_part_id' => $rmPart->id,
            'qty_required' => 2,
            'qty_consumed' => 0,
            'uom' => 'KGM',
            'sequence' => 1,
        ]);

        $issue = MaterialIssue::create([
            'issue_no' => 'MI-MODEL-TEST-1',
            'work_order_id' => $wo->id,
            'issue_date' => now()->toDateString(),
            'status' => 'posted',
        ]);

        MaterialIssueItem::create([
            'material_issue_id' => $issue->id,
            'work_order_item_id' => $woItem->id,
            'part_id' => $rmPart->id,
            'tag' => 'TAG-MI-MODEL-1',
            'qty' => 2,
            'uom' => 'KGM',
        ]);

        $today = now()->toDateString();

        $this->get(route('material-issues.index', ['date_from' => $today, 'date_to' => $today, 'search' => 'WO-MI-MODEL-1']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Outgoing/MaterialIssue/Index')
                ->where('rows.0.fg_model', 'MODEL-MI-FG-1')
                ->where('rows.0.materials.0.model', 'MODEL-MI-RM-1'));
    }
}
