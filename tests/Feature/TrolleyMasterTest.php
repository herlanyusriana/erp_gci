<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Location;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\Trolley;
use App\Models\User;
use App\Services\DeliveryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrolleyMasterTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@geumcheon.local')->firstOrFail();
        $this->actingAs($this->admin);
    }

    public function test_index_lists_trolleys_with_totals(): void
    {
        Trolley::query()->delete();
        Trolley::create(['code' => 'TRL-A', 'type' => 'internal', 'kind' => 'compbase', 'is_active' => true]);
        Trolley::create(['code' => 'TRL-B', 'type' => 'external', 'kind' => 'backplate', 'is_active' => false]);

        $page = $this->get(route('trolleys.index'))->assertOk()->viewData('page');

        $this->assertSame(2, $page['props']['total']);
        $this->assertSame(1, $page['props']['activeTotal']);
        $this->assertCount(2, $page['props']['trolleys']['data']);
    }

    public function test_search_and_filters_narrow_the_list(): void
    {
        Trolley::query()->delete();
        Trolley::create(['code' => 'B001', 'type' => 'external', 'kind' => 'backplate', 'is_active' => true]);
        Trolley::create(['code' => 'RF001', 'type' => 'external', 'kind' => 'reinforce', 'is_active' => true]);

        $search = $this->get(route('trolleys.index', ['search' => 'B00']))->assertOk()->viewData('page');
        $this->assertCount(1, $search['props']['trolleys']['data']);
        $this->assertSame('B001', $search['props']['trolleys']['data'][0]['code']);

        $kind = $this->get(route('trolleys.index', ['kind' => 'reinforce']))->assertOk()->viewData('page');
        $this->assertSame('RF001', $kind['props']['trolleys']['data'][0]['code']);
    }

    public function test_trolley_can_be_created_and_updated(): void
    {
        Trolley::query()->delete();

        $this->post(route('trolleys.store'), [
            'code' => 'TRL-NEW',
            'type' => 'internal',
            'kind' => 'compbase',
            'capacity' => 12,
            'location' => 'GCI',
            'notes' => 'Troli baru',
            'is_active' => true,
        ])->assertRedirect(route('trolleys.index'));

        $trolley = Trolley::where('code', 'TRL-NEW')->firstOrFail();
        $this->assertSame('internal', $trolley->type);
        $this->assertSame(12, $trolley->capacity);
        $this->assertNotNull($trolley->synced_at);

        $this->put(route('trolleys.update', $trolley), [
            'code' => 'TRL-NEW',
            'type' => 'external',
            'kind' => 'backplate',
            'is_active' => false,
        ])->assertRedirect(route('trolleys.index'));

        $trolley->refresh();
        $this->assertSame('external', $trolley->type);
        $this->assertFalse($trolley->is_active);
    }

    public function test_duplicate_code_is_rejected(): void
    {
        Trolley::query()->delete();
        Trolley::create(['code' => 'TRL-DUP', 'type' => 'internal', 'is_active' => true]);

        $this->post(route('trolleys.store'), [
            'code' => 'TRL-DUP',
            'type' => 'internal',
        ])->assertSessionHasErrors('code');
    }

    public function test_invalid_type_and_kind_are_rejected(): void
    {
        $this->post(route('trolleys.store'), [
            'code' => 'TRL-BAD',
            'type' => 'spaceship',
            'kind' => 'banana',
        ])->assertSessionHasErrors(['type', 'kind']);
    }

    public function test_trolley_can_be_deleted(): void
    {
        Trolley::query()->delete();
        $trolley = Trolley::create(['code' => 'TRL-DEL', 'type' => 'internal', 'is_active' => true]);

        $this->delete(route('trolleys.destroy', $trolley))->assertRedirect(route('trolleys.index'));

        $this->assertNull(Trolley::find($trolley->id));
    }

    public function test_inactive_trolley_is_hidden_from_the_picker(): void
    {
        Trolley::query()->delete();
        Trolley::create(['code' => 'TRL-ON', 'type' => 'internal', 'is_active' => true]);
        Trolley::create(['code' => 'TRL-OFF', 'type' => 'internal', 'is_active' => false]);

        $codes = collect(app(DeliveryService::class)->availableTrolleys())->pluck('code')->all();

        $this->assertContains('TRL-ON', $codes);
        $this->assertNotContains('TRL-OFF', $codes);
    }

    public function test_scanning_an_inactive_trolley_is_rejected(): void
    {
        Trolley::query()->delete();
        Trolley::create(['code' => 'TRL-OFF', 'type' => 'internal', 'is_active' => false]);

        $part = Part::where('part_number', 'AAN30056405')->firstOrFail();
        PartStock::create([
            'part_id' => $part->id,
            'tag' => 'TAG-TRL-'.uniqid(),
            'qty' => 5,
            'qty_unit' => 'PCS',
            'location_code' => Location::firstOrCreate(
                ['code' => 'TPL_DONGSHIN'],
                ['name' => 'TPL DONGSHIN', 'type' => Location::TYPE_MACHINE, 'is_active' => true],
            )->code,
            'received_at' => now(),
        ]);

        $delivery = Delivery::create([
            'delivery_no' => Delivery::generateDeliveryNo(now()),
            'customer_id' => Customer::create([
                'customer_code' => 'TRL-CUST-'.uniqid(),
                'customer_name' => 'Trolley Customer',
                'is_active' => true,
            ])->id,
            'delivery_date' => now()->toDateString(),
            'status' => Delivery::STATUS_DRAFT,
        ]);

        $delivery->items()->create([
            'part_id' => $part->id,
            'qty' => 5,
            'qty_unit' => 'PCS',
            'sequence' => 1,
        ]);

        $this->post(route('deliveries.scan', $delivery), ['code' => 'TRL-OFF'])
            ->assertSessionHasErrors('code');
    }

    public function test_csv_import_creates_and_updates_by_code(): void
    {
        Trolley::query()->delete();

        $csv = <<<'CSV'
ID,Code,Type,Kind,Status,Capacity,Location,Notes
1,IMP-1,external,backplate,in,,GCI,
2,IMP-2,internal,compbase,out,,LG,
CSV;

        $file = UploadedFile::fake()->createWithContent('trolleys.csv', $csv);

        $this->post(route('trolleys.import'), ['file' => $file])
            ->assertRedirect(route('trolleys.index'));

        $this->assertSame(2, Trolley::count());
        $this->assertSame('backplate', Trolley::where('code', 'IMP-1')->value('kind'));

        // Impor ulang dengan nilai berubah: hanya memperbarui, tidak menduplikasi.
        $csv2 = <<<'CSV'
ID,Code,Type,Kind,Status,Capacity,Location,Notes
1,IMP-1,external,reinforce,in,,JKT,
CSV;

        $this->post(route('trolleys.import'), [
            'file' => UploadedFile::fake()->createWithContent('trolleys.csv', $csv2),
        ])->assertRedirect(route('trolleys.index'));

        $this->assertSame(2, Trolley::count());
        $this->assertSame('reinforce', Trolley::where('code', 'IMP-1')->value('kind'));
        $this->assertSame('JKT', Trolley::where('code', 'IMP-1')->value('location'));
    }

    public function test_csv_without_code_column_is_rejected(): void
    {
        Trolley::query()->delete();
        $file = UploadedFile::fake()->createWithContent('bad.csv', "Foo,Bar\n1,2\n");

        $this->post(route('trolleys.import'), ['file' => $file])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, DB::table('trolleys')->count());
    }

    public function test_mutating_trolleys_requires_permission(): void
    {
        $qc = User::where('email', 'qc@geumcheon.local')->firstOrFail();

        // QC boleh melihat daftar (trolley.view ada di viewAll) tetapi tidak boleh mengubah.
        $this->actingAs($qc)->get(route('trolleys.index'))->assertOk();
        $this->actingAs($qc)->post(route('trolleys.store'), [
            'code' => 'TRL-NO',
            'type' => 'internal',
        ])->assertForbidden();
    }
}
