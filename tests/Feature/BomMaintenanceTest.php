<?php

namespace Tests\Feature;

use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Machine;
use App\Models\Part;
use App\Models\PartType;
use App\Models\Process;
use App\Models\User;
use App\Services\WoService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BomMaintenanceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::where('email', 'admin@geumcheon.local')->firstOrFail());
    }

    private function makePart(string $typeCode, string $prefix = 'BOMTEST'): Part
    {
        $type = PartType::firstOrCreate(['code' => $typeCode], ['name' => $typeCode]);

        return Part::create([
            'part_number' => $prefix.'-'.uniqid(),
            'part_name' => 'Test '.$typeCode,
            'part_type_id' => $type->id,
            'is_active' => true,
        ]);
    }

    /**
     * FG yang belum punya BOM. Tabel boms.part_id unik, jadi tidak boleh memakai
     * part FG dari seeder yang sudah memiliki BOM.
     */
    private function makeFgWithoutBom(): Part
    {
        $fg = $this->makePart('FG');
        $this->assertFalse(Bom::withTrashed()->where('part_id', $fg->id)->exists());

        return $fg;
    }

    /**
     * @return array<string, mixed>
     */
    private function item(array $overrides = []): array
    {
        $child = $overrides['child_part_id'] ?? $this->makePart('MATERIAL')->id;
        $parent = $overrides['parent_part_id'] ?? $this->makePart('WIP')->id;
        $process = Process::query()->orderBy('id')->firstOrFail();
        $machine = Machine::query()->orderBy('id')->firstOrFail();

        return $overrides + [
            'sequence' => 10,
            'process_id' => $process->id,
            'machine_id' => $machine->id,
            'parent_part_id' => $parent,
            'parent_part_name' => 'Parent WIP',
            'parent_qty' => 1,
            'parent_uom' => 'PCS',
            'child_part_id' => $child,
            'child_part_name' => 'Child Material',
            'size' => 'T1.2',
            'child_qty' => 2.5,
            'uom_rm' => 'PCS',
            'special_code' => 'SC-01',
            'source' => 'Prod',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Part $fg, array $items, array $overrides = []): array
    {
        return $overrides + [
            'part_id' => $fg->id,
            'version' => '1.0',
            'items' => $items,
        ];
    }

    public function test_create_page_is_available_for_users_with_bom_create_permission(): void
    {
        $this->get(route('boms.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Master/Bom/Form')
                ->where('bom', null)
                ->has('fgParts')
                ->has('materials')
                ->has('processes')
                ->has('machines')
                ->has('uoms'));
    }

    public function test_create_page_offers_only_fg_parts_without_a_bom(): void
    {
        $withBom = Bom::query()->firstOrFail();
        $available = $this->makeFgWithoutBom();
        $material = $this->makePart('MATERIAL');

        $this->get(route('boms.create'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($available, $withBom, $material) {
                $fgIds = collect($page->toArray()['props']['fgParts'])->pluck('id')->all();

                $this->assertContains($available->id, $fgIds);
                $this->assertNotContains($withBom->part_id, $fgIds);
                $this->assertNotContains($material->id, $fgIds);
            });
    }

    public function test_store_creates_bom_with_items_and_generated_bom_no(): void
    {
        $fg = $this->makeFgWithoutBom();
        $expectedNo = (int) Bom::withTrashed()->max('bom_no') + 1;

        $this->post(route('boms.store'), $this->payload($fg, [$this->item(), $this->item(['sequence' => 20])]))
            ->assertSessionHasNoErrors();

        $bom = Bom::query()->where('part_id', $fg->id)->firstOrFail();

        $this->assertSame($expectedNo, (int) $bom->bom_no);
        $this->assertTrue($bom->is_active);
        $this->assertSame('1.0', $bom->version);
        $this->assertSame(auth()->id(), $bom->created_by);
        $this->assertSame(2, BomItem::where('bom_id', $bom->id)->count());

        $this->assertDatabaseHas('bom_items', [
            'bom_id' => $bom->id,
            'sequence' => 10,
            'child_qty' => 2.5,
            'uom_rm' => 'PCS',
            'size' => 'T1.2',
            'special_code' => 'SC-01',
            'source' => 'Prod',
        ]);

        $this->assertDatabaseHas('boms', ['id' => $bom->id, 'bom_no' => $expectedNo, 'is_active' => true]);
    }

    public function test_store_keeps_every_sent_item_field(): void
    {
        $fg = $this->makeFgWithoutBom();
        $child = $this->makePart('MATERIAL');
        $parent = $this->makePart('WIP');
        $process = Process::query()->orderBy('id')->firstOrFail();
        $machine = Machine::query()->orderBy('id')->firstOrFail();

        $this->post(route('boms.store'), $this->payload($fg, [[
            'sequence' => 30,
            'process_id' => $process->id,
            'machine_id' => $machine->id,
            'parent_part_id' => $parent->id,
            'child_part_id' => $child->id,
            'child_qty' => 4,
            'uom_rm' => 'PCS',
            'size' => 'T2.0',
            'special_code' => 'SC-02',
            'source' => 'Vendor',
        ]]))->assertSessionHasNoErrors();

        $bom = Bom::query()->where('part_id', $fg->id)->firstOrFail();
        $item = BomItem::where('bom_id', $bom->id)->firstOrFail();

        $this->assertSame(30, (int) $item->sequence);
        $this->assertSame($process->id, (int) $item->process_id);
        $this->assertSame($machine->id, (int) $item->machine_id);
        $this->assertSame($parent->id, (int) $item->parent_part_id);
        $this->assertSame($child->id, (int) $item->child_part_id);
        $this->assertSame(4.0, (float) $item->child_qty);
        $this->assertSame('PCS', $item->uom_rm);
        $this->assertSame('T2.0', $item->size);
        $this->assertSame('SC-02', $item->special_code);
        $this->assertSame('Vendor', $item->source);
        $this->assertTrue($item->is_active);
    }

    public function test_store_rejects_part_that_is_not_fg(): void
    {
        $material = $this->makePart('MATERIAL');

        $this->post(route('boms.store'), $this->payload($material, [$this->item()]))
            ->assertSessionHasErrors(['part_id']);

        $this->assertSame(0, Bom::withTrashed()->where('part_id', $material->id)->count());
    }

    public function test_store_rejects_unknown_part(): void
    {
        $fg = $this->makeFgWithoutBom();

        $this->post(route('boms.store'), $this->payload($fg, [$this->item()], ['part_id' => 999999]))
            ->assertSessionHasErrors(['part_id']);
    }

    public function test_store_rejects_fg_that_already_has_a_bom(): void
    {
        $existing = Bom::query()->firstOrFail();

        $this->post(route('boms.store'), $this->payload($existing->part, [$this->item()]))
            ->assertSessionHasErrors(['part_id']);

        $this->assertSame(1, Bom::withTrashed()->where('part_id', $existing->part_id)->count());
        $this->assertSame(0, BomItem::where('child_part_name', 'Child Material')->count());
    }

    public function test_store_requires_child_part_and_quantity(): void
    {
        $fg = $this->makeFgWithoutBom();

        $this->post(route('boms.store'), $this->payload($fg, [
            ['sequence' => 10, 'child_qty' => 1, 'uom_rm' => 'PCS'],
        ]))->assertSessionHasErrors(['items.0.child_part_id']);

        $this->post(route('boms.store'), $this->payload($fg, [
            ['sequence' => 10, 'child_part_id' => $this->makePart('MATERIAL')->id, 'uom_rm' => 'PCS'],
        ]))->assertSessionHasErrors(['items.0.child_qty']);

        $this->assertSame(0, Bom::withTrashed()->where('part_id', $fg->id)->count());
    }

    public function test_store_requires_at_least_one_item(): void
    {
        $fg = $this->makeFgWithoutBom();

        $this->post(route('boms.store'), $this->payload($fg, []))
            ->assertSessionHasErrors(['items']);

        $this->assertSame(0, Bom::withTrashed()->where('part_id', $fg->id)->count());
    }

    public function test_store_writes_nothing_when_one_item_is_invalid(): void
    {
        $fg = $this->makeFgWithoutBom();

        $this->post(route('boms.store'), $this->payload($fg, [
            $this->item(),
            ['sequence' => 20, 'child_part_id' => 999999, 'child_qty' => 1, 'uom_rm' => 'PCS'],
        ]))->assertSessionHasErrors(['items.1.child_part_id']);

        $this->assertSame(0, Bom::withTrashed()->where('part_id', $fg->id)->count());
        $this->assertSame(0, BomItem::where('child_part_name', 'Child Material')->count());
    }

    public function test_new_bom_is_picked_up_by_the_active_bom_flow(): void
    {
        $fg = $this->makeFgWithoutBom();

        $this->post(route('boms.store'), $this->payload($fg, [$this->item()]))
            ->assertSessionHasNoErrors();

        $active = app(WoService::class)->activeBomFor($fg->id);

        $this->assertNotNull($active);
        $this->assertSame($fg->id, $active->part_id);
        $this->assertTrue($active->is_active);
        $this->assertCount(1, $active->items);
    }

    public function test_bom_no_is_not_reused_after_the_bom_is_soft_deleted(): void
    {
        $first = $this->makeFgWithoutBom();

        $this->post(route('boms.store'), $this->payload($first, [$this->item()]))
            ->assertSessionHasNoErrors();

        $bom = Bom::query()->where('part_id', $first->id)->firstOrFail();
        $firstNo = (int) $bom->bom_no;
        $bom->delete();

        $second = $this->makeFgWithoutBom();
        $this->post(route('boms.store'), $this->payload($second, [$this->item()]))
            ->assertSessionHasNoErrors();

        $created = Bom::query()->where('part_id', $second->id)->firstOrFail();

        $this->assertGreaterThan($firstNo, (int) $created->bom_no);
        $this->assertSame(1, Bom::onlyTrashed()->where('bom_no', $firstNo)->count());
    }

    public function test_store_is_forbidden_without_bom_create_permission(): void
    {
        $fg = $this->makeFgWithoutBom();

        $this->actingAs(User::where('email', 'production@geumcheon.local')->firstOrFail());

        $this->post(route('boms.store'), $this->payload($fg, [$this->item()]))
            ->assertForbidden();

        $this->assertSame(0, Bom::withTrashed()->where('part_id', $fg->id)->count());
    }

    public function test_create_page_is_forbidden_without_bom_create_permission(): void
    {
        $this->actingAs(User::where('email', 'warehouse@geumcheon.local')->firstOrFail());

        $this->get(route('boms.create'))->assertForbidden();
    }

    public function test_ppic_user_can_create_a_bom(): void
    {
        $fg = $this->makeFgWithoutBom();

        $this->actingAs(User::where('email', 'ppic@geumcheon.local')->firstOrFail());

        $this->post(route('boms.store'), $this->payload($fg, [$this->item()]))
            ->assertSessionHasNoErrors();

        $this->assertTrue(Bom::query()->where('part_id', $fg->id)->exists());
    }
}
