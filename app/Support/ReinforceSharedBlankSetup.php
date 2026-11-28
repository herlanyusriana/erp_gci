<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Part;
use App\Models\PartType;
use App\Models\Uom;
use Illuminate\Support\Facades\DB;

class ReinforceSharedBlankSetup
{
    /**
     * Pasangan FG REINFORCE,ASSEMBLY yang dipress bersama di TPL KUKIL (Seq 1–3
     * 1 blank, Seq 4 Bending terpisah menjadi WIP4 Left & WIP4 Right).
     *
     * @var list<array{model: string, blank_no: string, left_no: string, right_no: string}>
     */
    public const PAIRS = [
        ['model' => 'OMEGA', 'blank_no' => 'AHF73269903A', 'left_no' => 'AHF73269912', 'right_no' => 'AHF73269903'],
        ['model' => 'VT 12', 'blank_no' => 'AHF73710119A', 'left_no' => 'AHF73710119', 'right_no' => 'AHF73710120'],
        ['model' => 'VT 4', 'blank_no' => 'AHF73710109A', 'left_no' => 'AHF73710110', 'right_no' => 'AHF73710109'],
        ['model' => 'VT 6,7', 'blank_no' => 'AHF73710101A', 'left_no' => 'AHF73710102', 'right_no' => 'AHF73710101'],
        ['model' => 'VT 8,9', 'blank_no' => 'AHF73710107A', 'left_no' => 'AHF73710107', 'right_no' => 'AHF73710108'],
    ];

    public static function apply(): void
    {
        $wipTypeId = PartType::query()->whereRaw("LOWER(code) = 'wip'")->value('id');
        $pcsUomId = Uom::query()->whereRaw("UPPER(code) = 'PCS'")->value('id');

        if ($wipTypeId === null) {
            return;
        }

        DB::transaction(function () use ($wipTypeId, $pcsUomId): void {
            foreach (self::PAIRS as $pair) {
                $leftFg = Part::query()->where('part_number', $pair['left_no'])->first();
                $rightFg = Part::query()->where('part_number', $pair['right_no'])->first();
                if ($leftFg === null || $rightFg === null) {
                    continue;
                }

                $leftBom = Bom::query()->where('part_id', $leftFg->id)->where('is_active', true)->first();
                $rightBom = Bom::query()->where('part_id', $rightFg->id)->where('is_active', true)->first();
                if ($leftBom === null || $rightBom === null) {
                    continue;
                }

                $leftPress = $leftBom->items()->whereIn('sequence', [1, 2, 3, 4])->orderBy('sequence')->get()->keyBy('sequence');
                $rightPress = $rightBom->items()->whereIn('sequence', [1, 2, 3, 4])->orderBy('sequence')->get()->keyBy('sequence');

                // Sudah terkonversi bila salah satu FG tidak lagi memiliki Seq 1–4
                // dan BOM blank bersama sudah tersedia.
                if (! $leftPress->has(1) || ! $rightPress->has(1)) {
                    continue;
                }

                $step1R = $rightPress->get(1);
                $step1L = $leftPress->get(1);
                $step2Base = $rightPress->get(2) ?? $leftPress->get(2);
                $step3Base = $rightPress->get(3) ?? $leftPress->get(3);
                $step4R = $rightPress->get(4);
                $step4L = $leftPress->get(4);

                if ($step4R === null || $step4L === null) {
                    continue;
                }

                $blankPart = self::upsertWipPart(
                    $pair['blank_no'],
                    'REINFORCE,ASSEMBLY',
                    $pair['model'].' (L+R)',
                    (int) $wipTypeId,
                    $pcsUomId ? (int) $pcsUomId : null,
                );
                $wip1 = self::upsertWipPart(
                    $pair['blank_no'].'-WIP1',
                    '1/4 - Draw',
                    'REINFORCE,ASSEMBLY '.$pair['model'].' (L+R)',
                    (int) $wipTypeId,
                    $pcsUomId ? (int) $pcsUomId : null,
                );
                $wip2 = self::upsertWipPart(
                    $pair['blank_no'].'-WIP2',
                    '2/4 - Trimming',
                    'REINFORCE,ASSEMBLY '.$pair['model'].' (L+R)',
                    (int) $wipTypeId,
                    $pcsUomId ? (int) $pcsUomId : null,
                );
                $wip3 = self::upsertWipPart(
                    $pair['blank_no'].'-WIP3',
                    '3/4 - Piercing',
                    'REINFORCE,ASSEMBLY '.$pair['model'].' (L+R)',
                    (int) $wipTypeId,
                    $pcsUomId ? (int) $pcsUomId : null,
                );

                $blankBom = Bom::withTrashed()->where('part_id', $blankPart->id)->first();
                if ($blankBom === null) {
                    $blankBom = Bom::create([
                        'part_id' => $blankPart->id,
                        'bom_no' => Bom::generateBomNo(),
                        'version' => '1.0',
                        'is_active' => true,
                    ]);
                } else {
                    if ($blankBom->trashed()) {
                        $blankBom->restore();
                    }
                    $blankBom->update(['is_active' => true]);
                    $blankBom->items()->delete();
                }

                $coilQty = max((float) $step1R->child_qty, (float) $step1L->child_qty);

                $blankRows = [
                    [
                        'sequence' => 1,
                        'process_id' => $step1R->process_id,
                        'machine_id' => $step1R->machine_id,
                        'parent_part_id' => $wip1->id,
                        'parent_part_name' => $wip1->part_name,
                        'parent_qty' => 1,
                        'parent_uom' => 'PCS',
                        'child_part_id' => $step1R->child_part_id,
                        'child_part_name' => $step1R->child_part_name,
                        'size' => $step1R->size,
                        'child_qty' => $coilQty,
                        'uom_rm' => $step1R->uom_rm,
                        'special_code' => $step1R->special_code,
                        'source' => $step1R->source,
                        'output_type' => BomItem::OUTPUT_NORMAL,
                    ],
                    [
                        'sequence' => 2,
                        'process_id' => $step2Base?->process_id ?? $step1R->process_id,
                        'machine_id' => $step2Base?->machine_id ?? $step1R->machine_id,
                        'parent_part_id' => $wip2->id,
                        'parent_part_name' => $wip2->part_name,
                        'parent_qty' => 1,
                        'parent_uom' => 'PCS',
                        'child_part_id' => $wip1->id,
                        'child_part_name' => $wip1->part_name,
                        'child_qty' => 1,
                        'uom_rm' => 'PCS',
                        'source' => 'PROD',
                        'output_type' => BomItem::OUTPUT_NORMAL,
                    ],
                    [
                        'sequence' => 3,
                        'process_id' => $step3Base?->process_id ?? $step1R->process_id,
                        'machine_id' => $step3Base?->machine_id ?? $step1R->machine_id,
                        'parent_part_id' => $wip3->id,
                        'parent_part_name' => $wip3->part_name,
                        'parent_qty' => 1,
                        'parent_uom' => 'PCS',
                        'child_part_id' => $wip2->id,
                        'child_part_name' => $wip2->part_name,
                        'child_qty' => 1,
                        'uom_rm' => 'PCS',
                        'source' => 'PROD',
                        'output_type' => BomItem::OUTPUT_NORMAL,
                    ],
                    [
                        'sequence' => 4,
                        'process_id' => $step4R->process_id,
                        'machine_id' => $step4R->machine_id,
                        'parent_part_id' => $step4R->parent_part_id,
                        'parent_part_name' => $step4R->parent_part_name,
                        'parent_qty' => 1,
                        'parent_uom' => $step4R->parent_uom ?: 'PCS',
                        'child_part_id' => $wip3->id,
                        'child_part_name' => $wip3->part_name,
                        'child_qty' => 1,
                        'uom_rm' => 'PCS',
                        'source' => 'PROD',
                        'output_type' => BomItem::OUTPUT_SHARED,
                    ],
                    [
                        'sequence' => 4,
                        'process_id' => $step4L->process_id,
                        'machine_id' => $step4L->machine_id,
                        'parent_part_id' => $step4L->parent_part_id,
                        'parent_part_name' => $step4L->parent_part_name,
                        'parent_qty' => 1,
                        'parent_uom' => $step4L->parent_uom ?: 'PCS',
                        'child_part_id' => $wip3->id,
                        'child_part_name' => $wip3->part_name,
                        'child_qty' => 1,
                        'uom_rm' => 'PCS',
                        'source' => 'PROD',
                        'output_type' => BomItem::OUTPUT_SHARED,
                    ],
                ];

                foreach ($blankRows as $row) {
                    $blankBom->items()->create($row + ['is_active' => true]);
                }

                $leftBom->items()->whereIn('sequence', [1, 2, 3, 4])->delete();
                $rightBom->items()->whereIn('sequence', [1, 2, 3, 4])->delete();
            }
        });
    }

    private static function upsertWipPart(string $partNo, string $partName, string $model, int $wipTypeId, ?int $uomId): Part
    {
        $part = Part::withTrashed()->updateOrCreate(
            ['part_number' => $partNo],
            [
                'part_name' => $partName,
                'part_type_id' => $wipTypeId,
                'model' => $model,
                'uom_id' => $uomId,
                'is_active' => true,
            ],
        );

        if ($part->trashed()) {
            $part->restore();
        }

        return $part;
    }
}
