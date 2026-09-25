<?php

namespace Tests\Feature;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\IncomingReceive;
use App\Models\Location;
use App\Models\Part;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LocationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_location_permissions_are_registered_for_the_warehouse_role(): void
    {
        $warehouse = Role::where('name', 'warehouse')->firstOrFail();
        $names = $warehouse->permissions->pluck('name')->all();

        foreach (['location.view', 'location.create', 'location.update', 'location.delete'] as $permission) {
            $this->assertContains($permission, $names);
        }
    }

    public function test_reseeding_does_not_duplicate_location_permissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(1, Permission::where('name', 'location.view')->count());
    }

    public function test_location_code_must_be_unique(): void
    {
        Location::create(['code' => 'A-01', 'name' => 'Rak A1']);

        $this->expectException(QueryException::class);

        Location::create(['code' => 'A-01', 'name' => 'Rak Duplikat']);
    }

    public function test_location_is_soft_deleted(): void
    {
        $location = Location::create(['code' => 'A-02', 'name' => 'Rak A2']);

        $location->delete();

        $this->assertSoftDeleted('locations', ['id' => $location->id]);
        $this->assertNull(Location::find($location->id));
    }

    public function test_location_qr_payload_follows_the_machine_label_shape(): void
    {
        $location = Location::create(['code' => 'A-03', 'name' => 'Rak A3']);

        $this->assertSame([
            'type' => 'location',
            'location_id' => $location->id,
            'location_code' => 'A-03',
            'location_name' => 'Rak A3',
        ], $location->qrPayload());
    }

    public function test_location_index_renders(): void
    {
        Location::create(['code' => 'B-01', 'name' => 'Rak B1']);

        $this->actingAs($this->user('warehouse@geumcheon.local'))
            ->get(route('locations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/Location/Index')
                ->has('locations.data', 1));
    }

    public function test_location_store_creates_a_location(): void
    {
        $this->actingAs($this->user('warehouse@geumcheon.local'))
            ->post(route('locations.store'), ['code' => 'C-01', 'name' => 'Rak C1'])
            ->assertRedirect(route('locations.index'));

        $this->assertDatabaseHas('locations', ['code' => 'C-01', 'name' => 'Rak C1']);
    }

    public function test_location_store_rejects_a_duplicate_code(): void
    {
        Location::create(['code' => 'C-02', 'name' => 'Rak C2']);

        $this->actingAs($this->user('warehouse@geumcheon.local'))
            ->post(route('locations.store'), ['code' => 'C-02', 'name' => 'Lain'])
            ->assertSessionHasErrors('code');
    }

    public function test_location_store_is_forbidden_without_the_create_permission(): void
    {
        $this->actingAs($this->user('qc@geumcheon.local'))
            ->post(route('locations.store'), ['code' => 'D-01', 'name' => 'Rak D1'])
            ->assertForbidden();

        $this->assertDatabaseMissing('locations', ['code' => 'D-01']);
    }

    public function test_location_update_changes_the_name(): void
    {
        $location = Location::create(['code' => 'E-01', 'name' => 'Nama Lama']);

        $this->actingAs($this->user('warehouse@geumcheon.local'))
            ->put(route('locations.update', $location), ['code' => 'E-01', 'name' => 'Nama Baru'])
            ->assertRedirect(route('locations.index'));

        $this->assertSame('Nama Baru', $location->fresh()->name);
    }

    public function test_location_destroy_deletes_an_unused_location(): void
    {
        $location = Location::create(['code' => 'F-01', 'name' => 'Rak F1']);

        $this->actingAs($this->user('warehouse@geumcheon.local'))
            ->delete(route('locations.destroy', $location))
            ->assertRedirect(route('locations.index'));

        $this->assertSoftDeleted('locations', ['id' => $location->id]);
    }

    public function test_location_destroy_refuses_a_location_used_by_receiving(): void
    {
        $location = Location::create(['code' => 'G-01', 'name' => 'Rak G1']);
        $this->receiveIntoLocation('G-01');

        $this->actingAs($this->user('warehouse@geumcheon.local'))
            ->delete(route('locations.destroy', $location))
            ->assertRedirect(route('locations.index'))
            ->assertSessionHas('error');

        $this->assertNotSoftDeleted('locations', ['id' => $location->id]);
    }

    public function test_location_label_renders_the_code_and_name(): void
    {
        $location = Location::create(['code' => 'H-01', 'name' => 'Rak H1']);

        $this->actingAs($this->user('warehouse@geumcheon.local'))
            ->get(route('locations.label', $location))
            ->assertOk()
            ->assertSee('LOCATION')
            ->assertSee('H-01', false)
            ->assertSee('Rak H1', false);
    }

    private function receiveIntoLocation(?string $code, ?string $tag = null): IncomingReceive
    {
        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARR-LOC-'.Str::upper(Str::random(6)),
            'status' => 'pending',
            'is_local' => false,
        ]);
        $arrivalItem = IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'qty_goods' => 10,
            'unit_goods' => 'PCS',
        ]);

        return IncomingReceive::create([
            'arrival_item_id' => $arrivalItem->id,
            'tag' => $tag ?? 'LOC-TAG-'.Str::upper(Str::random(4)),
            'qty' => 10,
            'qty_unit' => 'PCS',
            'location_code' => $code,
        ]);
    }

    private function arrivalItem(string $unitGoods = 'PCS', float $weightNett = 0): IncomingArrivalItem
    {
        $part = Part::where('part_number', 'AAN30056405')->firstOrFail();
        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-LOC-'.Str::upper(Str::random(6)),
            'is_local' => false,
            'status' => 'draft',
        ]);

        return IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $part->id,
            'qty_goods' => 100,
            'unit_goods' => $unitGoods,
            'weight_nett' => $weightNett,
        ]);
    }

    /** @return array<string, mixed> */
    private function receivePayload(string $tag, ?string $locationCode = null): array
    {
        return [
            'receive_date' => now()->toDateString(),
            'location_code' => $locationCode,
            'tags' => [[
                'tag' => $tag,
                'qty' => 10,
                'qty_unit' => 'PCS',
            ]],
        ];
    }

    public function test_receive_stores_the_location_code(): void
    {
        Location::create(['code' => 'R-01', 'name' => 'Rak R1']);
        $item = $this->arrivalItem();

        $this->actingAs($this->user('warehouse@geumcheon.local'))
            ->post(route('receive.store', $item), $this->receivePayload('LOC-TAG-A', 'R-01'))
            ->assertRedirect();

        $this->assertDatabaseHas('incoming_receives', ['tag' => 'LOC-TAG-A', 'location_code' => 'R-01']);
    }

    public function test_receive_rejects_an_unknown_location_code(): void
    {
        $item = $this->arrivalItem();

        $this->actingAs($this->user('warehouse@geumcheon.local'))
            ->post(route('receive.store', $item), $this->receivePayload('LOC-TAG-B', 'TIDAK-ADA'))
            ->assertSessionHasErrors('location_code');

        $this->assertDatabaseMissing('incoming_receives', ['tag' => 'LOC-TAG-B']);
    }

    public function test_receive_rejects_an_inactive_location_code(): void
    {
        Location::create(['code' => 'R-02', 'name' => 'Rak Nonaktif', 'is_active' => false]);
        $item = $this->arrivalItem();

        $this->actingAs($this->user('warehouse@geumcheon.local'))
            ->post(route('receive.store', $item), $this->receivePayload('LOC-TAG-C', 'R-02'))
            ->assertSessionHasErrors('location_code');
    }

    public function test_receive_still_saves_without_a_location(): void
    {
        $item = $this->arrivalItem();

        $this->actingAs($this->user('warehouse@geumcheon.local'))
            ->post(route('receive.store', $item), $this->receivePayload('LOC-TAG-D'))
            ->assertRedirect();

        $this->assertDatabaseHas('incoming_receives', ['tag' => 'LOC-TAG-D', 'location_code' => null]);
    }

    public function test_receive_update_changes_the_location(): void
    {
        Location::create(['code' => 'R-03', 'name' => 'Rak R3']);
        $receive = $this->receiveIntoLocation('R-01');

        $this->actingAs($this->user('warehouse@geumcheon.local'))
            ->put(route('receive.update', $receive), [
                'receive_date' => now()->toDateString(),
                'tag' => $receive->tag,
                'qty' => 10,
                'location_code' => 'R-03',
            ])
            ->assertRedirect();

        $this->assertSame('R-03', $receive->fresh()->location_code);
    }

    public function test_location_setup_lists_receives_without_a_location(): void
    {
        Location::create(['code' => 'R-04', 'name' => 'Rak R4']);
        $this->receiveIntoLocation('R-04');
        $without = $this->receiveWithoutLocation('NO-LOC-TAG');

        $this->actingAs($this->user('warehouse@geumcheon.local'))
            ->get(route('locations.setup'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/Location/Setup')
                ->has('receives.data', 1)
                ->where('receives.data.0.id', $without->id));
    }

    public function test_location_setup_assigns_a_location_to_selected_receives(): void
    {
        Location::create(['code' => 'R-05', 'name' => 'Rak R5']);
        $first = $this->receiveWithoutLocation('SETUP-TAG-1');
        $second = $this->receiveWithoutLocation('SETUP-TAG-2');
        $untouched = $this->receiveWithoutLocation('SETUP-TAG-3');

        $this->actingAs($this->user('warehouse@geumcheon.local'))
            ->post(route('locations.setup.assign'), [
                'location_code' => 'R-05',
                'receive_ids' => [$first->id, $second->id],
            ])
            ->assertRedirect(route('locations.setup'));

        $this->assertSame('R-05', $first->fresh()->location_code);
        $this->assertSame('R-05', $second->fresh()->location_code);
        $this->assertNull($untouched->fresh()->location_code);
    }

    public function test_location_setup_assign_is_forbidden_without_the_update_permission(): void
    {
        Location::create(['code' => 'R-06', 'name' => 'Rak R6']);
        $receive = $this->receiveWithoutLocation('SETUP-TAG-4');

        $this->actingAs($this->user('qc@geumcheon.local'))
            ->post(route('locations.setup.assign'), [
                'location_code' => 'R-06',
                'receive_ids' => [$receive->id],
            ])
            ->assertForbidden();

        $this->assertNull($receive->fresh()->location_code);
    }

    public function test_location_setup_assign_rejects_an_unknown_location(): void
    {
        $receive = $this->receiveWithoutLocation('SETUP-TAG-5');

        $this->actingAs($this->user('warehouse@geumcheon.local'))
            ->post(route('locations.setup.assign'), [
                'location_code' => 'TIDAK-ADA',
                'receive_ids' => [$receive->id],
            ])
            ->assertSessionHasErrors('location_code');
    }

    private function receiveWithoutLocation(string $tag): IncomingReceive
    {
        return $this->receiveIntoLocation(null, $tag);
    }
}
