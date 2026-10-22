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
}
