<?php

namespace Tests\Feature;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\Part;
use App\Models\PartPackingType;
use App\Models\PartSubstitute;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PartSubstituteAttributeTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('email', 'admin@geumcheon.local')->firstOrFail();
    }

    private function part(string $number): Part
    {
        return Part::where('part_number', $number)->firstOrFail();
    }

    private function supplier(string $code = 'SUP-PACK-001'): Supplier
    {
        return Supplier::firstOrCreate(
            ['supplier_code' => $code],
            ['supplier_name' => 'PT Packing Test', 'is_active' => true],
        );
    }

    /**
     * Catat satu item Arrival dengan packing type tertentu untuk sebuah part.
     */
    private function arrivalItemWithPacking(Part $part, ?string $packing, string $suffix): IncomingArrivalItem
    {
        $arrival = IncomingArrival::create([
            'arrival_no' => 'ARV-PACK-'.$suffix,
            'supplier_id' => $this->supplier()->id,
            'is_local' => false,
            'status' => 'draft',
        ]);

        return IncomingArrivalItem::create([
            'arrival_id' => $arrival->id,
            'part_id' => $part->id,
            'qty_goods' => 10,
            'unit_goods' => 'PCS',
            'unit_bundle' => $packing,
        ]);
    }

    public function test_packing_catalog_is_seeded_from_arrival_history(): void
    {
        $part = $this->part('AAN30056405');
        $this->arrivalItemWithPacking($part, 'PALLET', 'A1');

        $added = PartPackingType::syncFromArrivalHistory();

        $this->assertGreaterThanOrEqual(1, $added);
        $this->assertContains('PALLET', PartPackingType::codesFor($part->id));
        $this->assertDatabaseHas('part_packing_types', [
            'part_id' => $part->id,
            'code' => 'PALLET',
            'origin' => PartPackingType::ORIGIN_ARRIVAL,
        ]);
    }

    public function test_packing_catalog_sync_is_idempotent(): void
    {
        $part = $this->part('AAN30056405');
        $this->arrivalItemWithPacking($part, 'BOX', 'A2');

        PartPackingType::syncFromArrivalHistory();
        $secondRun = PartPackingType::syncFromArrivalHistory();

        $this->assertSame(0, $secondRun, 'Sinkronisasi ulang tidak boleh menambah baris ganda.');
        $this->assertSame(
            1,
            PartPackingType::where('part_id', $part->id)->where('code', 'BOX')->count(),
        );
    }

    public function test_manual_packing_type_is_available_for_the_part_without_creating_arrival(): void
    {
        $part = $this->part('CBKG07256C');
        $arrivalsBefore = IncomingArrivalItem::where('part_id', $part->id)->count();

        PartPackingType::register($part->id, 'bundle');

        $this->assertContains('BUNDLE', PartPackingType::codesFor($part->id));
        $this->assertDatabaseHas('part_packing_types', [
            'part_id' => $part->id,
            'code' => 'BUNDLE',
            'origin' => PartPackingType::ORIGIN_MANUAL,
        ]);
        $this->assertSame(
            $arrivalsBefore,
            IncomingArrivalItem::where('part_id', $part->id)->count(),
            'Menambah packing type manual tidak boleh membuat item Arrival.',
        );
    }

    public function test_packing_catalog_does_not_leak_between_parts(): void
    {
        $first = $this->part('AAN30056405');
        $second = $this->part('CBKG07256C');

        PartPackingType::register($first->id, 'ROLL');

        $this->assertContains('ROLL', PartPackingType::codesFor($first->id));
        $this->assertNotContains('ROLL', PartPackingType::codesFor($second->id));
    }

    public function test_substitute_table_has_size_and_packing_type_columns(): void
    {
        $relation = PartSubstitute::query()->first();

        $this->assertNotNull($relation, 'Butuh minimal satu relasi substitute dari seeder.');
        $this->assertTrue(
            \Schema::hasColumn('part_substitutes', 'size'),
            'Kolom size harus ada pada part_substitutes.',
        );
        $this->assertTrue(
            \Schema::hasColumn('part_substitutes', 'packing_type'),
            'Kolom packing_type harus ada pada part_substitutes.',
        );
    }

    public function test_backfill_fills_only_relations_with_a_single_historical_packing(): void
    {
        $single = $this->part('AAN30056405');
        $ambiguous = $this->part('CBKG07256C');

        $this->arrivalItemWithPacking($single, 'PALLET', 'B1');
        $this->arrivalItemWithPacking($ambiguous, 'BOX', 'B2');
        $this->arrivalItemWithPacking($ambiguous, 'BAG', 'B3');

        PartPackingType::syncFromArrivalHistory();

        $supplier = $this->supplier();

        $singleRelation = PartSubstitute::create([
            'part_id' => $this->part('4000W4A003A')->id,
            'substitute_part_id' => $single->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
        ]);
        $ambiguousRelation = PartSubstitute::create([
            'part_id' => $this->part('PINCB01')->id,
            'substitute_part_id' => $ambiguous->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
        ]);

        PartSubstitute::backfillPackingType();

        $this->assertSame('PALLET', $singleRelation->fresh()->packing_type);
        $this->assertNull(
            $ambiguousRelation->fresh()->packing_type,
            'Nilai ganda tidak boleh ditebak sistem.',
        );
    }

    private function materialPart(string $number): Part
    {
        return Part::where('part_number', $number)->firstOrFail();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'part_id' => $this->materialPart('4000W4A003A')->id,
            'substitute_part_id' => $this->materialPart('CBKG07256C')->id,
            'supplier_id' => $this->supplier('SUP-ATTR-01')->id,
            'material_group' => 'STEEL',
            'size' => '25 x 30 mm',
            'packing_type' => 'PALLET',
            'source' => null,
            'is_active' => true,
        ], $overrides);
    }

    public function test_store_saves_size_and_packing_type_on_the_relation(): void
    {
        PartPackingType::register($this->materialPart('CBKG07256C')->id, 'PALLET');

        $this->actingAs($this->admin())
            ->post(route('substitutes.store'), $this->payload())
            ->assertRedirect(route('substitutes.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('part_substitutes', [
            'part_id' => $this->materialPart('4000W4A003A')->id,
            'substitute_part_id' => $this->materialPart('CBKG07256C')->id,
            'size' => '25 x 30 mm',
            'packing_type' => 'PALLET',
        ]);
    }

    public function test_store_rejects_packing_type_missing_from_the_part_catalog(): void
    {
        $this->actingAs($this->admin())
            ->post(route('substitutes.store'), $this->payload(['packing_type' => 'CRATE']))
            ->assertSessionHasErrors('packing_type');

        $this->assertDatabaseMissing('part_substitutes', [
            'part_id' => $this->materialPart('4000W4A003A')->id,
            'substitute_part_id' => $this->materialPart('CBKG07256C')->id,
        ]);
    }

    public function test_store_rejects_packing_type_belonging_to_another_part(): void
    {
        PartPackingType::register($this->materialPart('PINCB01')->id, 'BAG');

        $this->actingAs($this->admin())
            ->post(route('substitutes.store'), $this->payload(['packing_type' => 'BAG']))
            ->assertSessionHasErrors('packing_type');
    }

    public function test_store_requires_packing_type(): void
    {
        $payload = $this->payload();
        unset($payload['packing_type']);

        $this->actingAs($this->admin())
            ->post(route('substitutes.store'), $payload)
            ->assertSessionHasErrors('packing_type');
    }

    public function test_store_rejects_a_supplier_that_does_not_exist(): void
    {
        PartPackingType::register($this->materialPart('CBKG07256C')->id, 'PALLET');

        $this->actingAs($this->admin())
            ->post(route('substitutes.store'), $this->payload(['supplier_id' => 999999]))
            ->assertSessionHasErrors('supplier_id');
    }

    public function test_blank_size_is_stored_as_null(): void
    {
        PartPackingType::register($this->materialPart('CBKG07256C')->id, 'PALLET');

        $this->actingAs($this->admin())
            ->post(route('substitutes.store'), $this->payload(['size' => '   ']))
            ->assertSessionHasNoErrors();

        $relation = PartSubstitute::where('part_id', $this->materialPart('4000W4A003A')->id)
            ->where('substitute_part_id', $this->materialPart('CBKG07256C')->id)
            ->firstOrFail();

        $this->assertNull($relation->size);
    }

    public function test_update_saves_attributes_and_requires_packing_type_for_legacy_rows(): void
    {
        $part = $this->materialPart('4000W4A003A');
        $substitutePart = $this->materialPart('CBKG07256C');
        $supplier = $this->supplier('SUP-ATTR-02');

        $relation = PartSubstitute::create([
            'part_id' => $part->id,
            'substitute_part_id' => $substitutePart->id,
            'supplier_id' => $supplier->id,
            'is_active' => true,
        ]);
        $this->assertNull($relation->fresh()->packing_type);

        $payload = [
            'part_id' => $part->id,
            'substitute_part_id' => $substitutePart->id,
            'supplier_id' => $supplier->id,
            'size' => '10 mm',
            'is_active' => true,
        ];

        // Relasi lama tanpa packing type tidak boleh lolos sebelum dipilih.
        $this->actingAs($this->admin())
            ->put(route('substitutes.update', $relation), $payload)
            ->assertSessionHasErrors('packing_type');

        PartPackingType::register($substitutePart->id, 'ROLL');

        $this->actingAs($this->admin())
            ->put(route('substitutes.update', $relation), $payload + ['packing_type' => 'ROLL'])
            ->assertSessionHasNoErrors();

        $relation->refresh();
        $this->assertSame('10 mm', $relation->size);
        $this->assertSame('ROLL', $relation->packing_type);
        $this->assertSame(1, PartSubstitute::where('part_id', $part->id)
            ->where('substitute_part_id', $substitutePart->id)
            ->where('supplier_id', $supplier->id)
            ->count(), 'Update tidak boleh membuat record baru.');
    }

    public function test_uom_comes_from_the_substitute_part_master(): void
    {
        $substitutePart = $this->materialPart('CBKG07256C');
        $relation = PartSubstitute::create([
            'part_id' => $this->materialPart('4000W4A003A')->id,
            'substitute_part_id' => $substitutePart->id,
            'supplier_id' => $this->supplier('SUP-ATTR-03')->id,
            'packing_type' => 'PALLET',
            'is_active' => true,
        ]);

        $relation->load('substitutePart.uom');

        $this->assertSame(
            $substitutePart->uom?->code,
            $relation->substituteUomCode(),
            'UOM harus dibaca dari master part pengganti.',
        );
    }
}
