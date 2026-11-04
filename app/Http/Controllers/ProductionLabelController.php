<?php

namespace App\Http\Controllers;

use App\Models\MaterialIssueItem;
use App\Models\ProductionMaterialReceipt;
use App\Models\ProductionResult;
use App\Models\WorkOrder;
use App\Support\QrSvg;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Antrean cetak label WIP.
 *
 * APK hanya menyimpan laporan periode; label dicetak di sini oleh petugas
 * gudang, lalu diantar ke mesin. Label Good dan NG dicetak terpisah supaya
 * barang cacat tidak tercampur dengan barang lolos.
 */
class ProductionLabelController extends Controller
{
    public const KINDS = ['good', 'ng'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', WorkOrder::class);

        // Default ke laporan yang belum dicetak: itulah pekerjaan petugas gudang.
        $status = $request->input('status', ProductionResult::LABEL_PENDING);
        $date = $request->input('date') ?: now()->toDateString();

        $rows = ProductionResult::query()
            ->with(['workOrder:id,wo_no', 'parentPart:id,part_number,part_name', 'machine:id,machine_code,machine_name'])
            ->whereDate('result_date', $date)
            ->when($status !== null && $status !== '', fn ($q) => $q->where('label_status', $status))
            ->where(function ($q) {
                // Baris tanpa periode adalah data pra-production; tidak masuk antrean.
                $q->whereNotNull('period_start')->whereNotNull('period_end');
            })
            ->orderBy('period_start')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductionResult $row): array => [
                'id' => (int) $row->id,
                'work_order_id' => (int) $row->work_order_id,
                'wo_no' => $row->workOrder?->wo_no,
                'part_id' => (int) $row->parent_part_id,
                'part_number' => $row->parentPart?->part_number,
                'part_name' => $row->parentPart?->part_name,
                'machine_name' => $row->machine?->machine_name,
                'shift' => $row->shift,
                'period_label' => substr((string) $row->period_start, 0, 5).' - '.substr((string) $row->period_end, 0, 5),
                'is_backfilled' => (bool) $row->is_backfilled,
                'qty_good' => round((float) $row->qty_good, 4),
                'qty_reject' => round((float) $row->qty_reject, 4),
                'uom' => $row->uom,
                'label_status' => (string) $row->label_status,
            ])
            ->values()
            ->all();

        return Inertia::render('Production/Label/Index', [
            'rows' => $rows,
            'filters' => ['date' => $date, 'status' => $status],
            'statuses' => [
                ProductionResult::LABEL_PENDING,
                ProductionResult::LABEL_PRINTED,
                ProductionResult::LABEL_DELIVERED,
            ],
        ]);
    }

    public function printLabel(ProductionResult $result, string $kind)
    {
        Gate::authorize('viewAny', WorkOrder::class);

        abort_unless(in_array($kind, self::KINDS, true), 404);

        $qty = $kind === 'good' ? (float) $result->qty_good : (float) $result->qty_reject;
        abort_if($qty <= 0, 404, __('Tidak ada kuantitas untuk jenis label ini.'));

        $result->load([
            'workOrder.part:id,part_number,part_name',
            'workOrder.items:id,work_order_id,parent_part_id,process_id,sequence',
            'parentPart:id,part_number,part_name',
            'process:id,process_name',
            'machine:id,machine_code,machine_name',
            'reporter:id,name',
        ]);

        // Status bergerak pending -> printed; delivered tidak pernah mundur.
        if ($result->label_status === ProductionResult::LABEL_PENDING) {
            $result->update(['label_status' => ProductionResult::LABEL_PRINTED]);
        }

        $uniqueStepParentIds = collect($result->workOrder?->items ?? [])
            ->whereNotNull('parent_part_id')
            ->sortBy('sequence')
            ->pluck('parent_part_id')
            ->unique()
            ->values();
        $stepIndex = $uniqueStepParentIds->search((int) $result->parent_part_id);
        $stepFraction = ($stepIndex !== false && $uniqueStepParentIds->count() > 0)
            ? ($stepIndex + 1).'/'.$uniqueStepParentIds->count()
            : null;

        $invoiceNo = ProductionMaterialReceipt::query()
            ->where('work_order_id', $result->work_order_id)
            ->whereNotNull('invoice')
            ->value('invoice')
            ?? MaterialIssueItem::query()
                ->whereHas('materialIssue', fn ($q) => $q->where('work_order_id', $result->work_order_id))
                ->whereNotNull('invoice')
                ->value('invoice')
            ?? '-';

        $tagSeq = ProductionResult::query()
            ->where('work_order_id', $result->work_order_id)
            ->where('parent_part_id', $result->parent_part_id)
            ->where('id', '<=', $result->id)
            ->count() ?: 1;

        $monthBox = str_pad((string) (($result->result_date ?? now())->format('m')), 2, '0', STR_PAD_LEFT);

        return view('production.labels.wip', [
            'kind' => $kind,
            'qty' => $qty,
            'uom' => $result->uom ?: 'EA',
            'woNo' => $result->workOrder?->wo_no,
            'fgPartName' => $result->workOrder?->part?->part_name ?? $result->parentPart?->part_name,
            'partNumber' => $result->parentPart?->part_number,
            'partName' => $result->parentPart?->part_name,
            'processName' => $result->process?->process_name ?? $result->parentPart?->part_name,
            'stepFraction' => $stepFraction,
            'invoiceNo' => $invoiceNo,
            'tagSeq' => $tagSeq,
            'operatorName' => $result->reporter?->name ?? '#OPERATOR NAME#',
            'foremanName' => '#FOREMAN NAME#',
            'monthBox' => $monthBox,
            'machineName' => $result->machine?->machine_name,
            'shift' => $result->shift,
            'periodStart' => (string) $result->period_start,
            'periodEnd' => (string) $result->period_end,
            'resultDate' => $result->result_date?->toDateString(),
            'isBackfilled' => (bool) $result->is_backfilled,
            'locationCode' => '# LOCATION #',
            'qr' => QrSvg::make((string) json_encode([
                'type' => 'wip',
                'wo_id' => (int) $result->work_order_id,
                'part_id' => (int) $result->parent_part_id,
                'result_id' => (int) $result->id,
                'kind' => $kind,
            ]), 340, 0),
        ]);
    }

    public function deliver(ProductionResult $result): RedirectResponse
    {
        Gate::authorize('viewAny', WorkOrder::class);

        if ($result->label_status !== ProductionResult::LABEL_PRINTED) {
            throw ValidationException::withMessages([
                'label_status' => __('Label harus dicetak sebelum ditandai diantar.'),
            ]);
        }

        $result->update(['label_status' => ProductionResult::LABEL_DELIVERED]);

        return back()->with('success', __('Label ditandai sudah diantar.'));
    }
}
