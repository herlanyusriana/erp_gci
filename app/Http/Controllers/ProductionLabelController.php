<?php

namespace App\Http\Controllers;

use App\Models\ConfigMaster;
use App\Models\ProductionResult;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderConsumption;
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

        $timezone = (string) ConfigMaster::getValue('SYSTEM', 'timezone', 'Asia/Jakarta');
        // Default menampilkan seluruh status pada tanggal tersebut, agar baris
        // yang sudah pernah dibuka/dicetak tidak langsung menghilang dari tabel.
        $status = $request->input('status', '');
        $date = $request->input('date') ?: now($timezone)->toDateString();

        $rows = ProductionResult::query()
            ->with(['workOrder:id,wo_no', 'parentPart:id,part_number,part_name', 'machine:id,machine_code,machine_name', 'reporter:id,name'])
            ->whereDate('result_date', $date)
            ->when($status !== null && $status !== '', fn ($q) => $q->where('label_status', $status))
            ->where(function ($q) {
                // Baris tanpa periode adalah data pra-production; tidak masuk antrean.
                $q->whereNotNull('period_start')->whereNotNull('period_end');
            })
            ->orderBy('period_start')
            ->orderBy('id')
            ->get()
            ->map(function (ProductionResult $row): array {
                $carryOverIn = (float) (ProductionResult::query()
                    ->where('work_order_id', $row->work_order_id)
                    ->where('parent_part_id', $row->parent_part_id)
                    ->where('id', '<', $row->id)
                    ->where('label_carried_over_qty', '>', 0)
                    ->value('label_carried_over_qty') ?? 0.0);

                return [
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
                    'label_carried_over_qty' => round((float) ($row->label_carried_over_qty ?? 0), 4),
                    'carry_over_in' => round($carryOverIn, 4),
                    'uom' => $row->uom,
                    'label_status' => (string) $row->label_status,
                    'reporter_id' => $row->reported_by ? (int) $row->reported_by : null,
                    'reporter_name' => $row->reporter?->name,
                ];
            })
            ->values()
            ->all();

        // Daftar operator produksi dari tabel users (role production atau akun APK)
        // untuk dipilih pada modal cetak label WIP.
        $operators = User::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('apk_only', true)
                    ->orWhereHas('roles', fn ($r) => $r->whereIn('name', ['production', 'foreman']));
            })
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Production/Label/Index', [
            'rows' => $rows,
            'filters' => ['date' => $date, 'status' => $status],
            'statuses' => [
                ProductionResult::LABEL_PENDING,
                ProductionResult::LABEL_PRINTED,
                ProductionResult::LABEL_DELIVERED,
            ],
            'operators' => $operators,
            'timezone' => $timezone,
        ]);
    }

    public function printLabel(Request $request, ProductionResult $result, string $kind)
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

        // Cek apakah ada sisa carry-over dari periode sebelumnya pada WO & part yang sama
        $carryOverSource = null;
        $carryOverIn = 0.0;
        if ($kind === 'good') {
            $carryOverSource = ProductionResult::query()
                ->where('work_order_id', $result->work_order_id)
                ->where('parent_part_id', $result->parent_part_id)
                ->where('id', '<', $result->id)
                ->where('label_carried_over_qty', '>', 0)
                ->latest('id')
                ->first();

            if ($carryOverSource !== null) {
                $carryOverIn = (float) $carryOverSource->label_carried_over_qty;
                // Serap carry-over ke periode ini
                $result->update(['label_carry_over_source_id' => $carryOverSource->id]);
                $carryOverSource->update(['label_carried_over_qty' => 0]);
            }
        }

        $effectiveQty = $qty + $carryOverIn;

        // Parameter kemasan dari user (Troli / Box / Pallet)
        $packingType = strtoupper(trim((string) $request->input('packing_type', 'BOX')));
        if (! in_array($packingType, ['TROLI', 'BOX', 'PALLET'], true)) {
            $packingType = 'BOX';
        }

        $packingQtyInput = (float) $request->input('packing_qty', 0);
        $packingQty = $packingQtyInput > 0 ? $packingQtyInput : $effectiveQty;

        $deferRemainder = filter_var($request->input('defer_remainder', false), FILTER_VALIDATE_BOOLEAN);

        $labelSheets = [];
        if ($kind === 'good' && $packingQty > 0) {
            $fullPacks = (int) floor($effectiveQty / $packingQty);
            $remainder = round(fmod($effectiveQty, $packingQty), 4);

            if ($deferRemainder && $remainder > 0) {
                $result->update(['label_carried_over_qty' => $remainder]);
                $totalLabels = max(1, $fullPacks);
            } else {
                $totalLabels = $fullPacks + ($remainder > 0 ? 1 : 0);
            }

            for ($i = 1; $i <= $fullPacks; $i++) {
                $labelSheets[] = [
                    'label_number' => $i,
                    'total_labels' => $totalLabels,
                    'qty' => $packingQty,
                    'packing_type' => $packingType,
                    'is_remainder' => false,
                ];
            }

            if (! $deferRemainder && $remainder > 0) {
                $labelSheets[] = [
                    'label_number' => count($labelSheets) + 1,
                    'total_labels' => $totalLabels,
                    'qty' => $remainder,
                    'packing_type' => $packingType,
                    'is_remainder' => true,
                ];
            }

            if ($labelSheets === []) {
                $labelSheets[] = [
                    'label_number' => 1,
                    'total_labels' => 1,
                    'qty' => $effectiveQty,
                    'packing_type' => $packingType,
                    'is_remainder' => false,
                ];
            }
        } else {
            // Label NG atau default single sheet
            $labelSheets[] = [
                'label_number' => 1,
                'total_labels' => 1,
                'qty' => $effectiveQty,
                'packing_type' => $packingType,
                'is_remainder' => false,
            ];
        }

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

        // Asal bahan: ambil nota yang BENAR-BENAR terpakai oleh laporan periode
        // ini, bukan sembarang nota milik WO. Satu periode bisa memakai beberapa
        // nota sekaligus, jadi semuanya didaftar — bukan diambil satu asal-asalan.
        $consumedInvoices = WorkOrderConsumption::query()
            ->where('production_result_id', $result->id)
            ->whereNotNull('invoice_no')
            ->pluck('invoice_no')
            ->unique()
            ->values();

        $consumedTags = WorkOrderConsumption::query()
            ->where('production_result_id', $result->id)
            ->whereNotNull('source_tag')
            ->pluck('source_tag')
            ->unique()
            ->values();

        $invoiceLabel = match (true) {
            $consumedInvoices->isEmpty() => '-',
            $consumedInvoices->count() === 1 => (string) $consumedInvoices->first(),
            default => $consumedInvoices->implode(', '),
        };

        $supplierLabel = WorkOrderConsumption::query()
            ->where('production_result_id', $result->id)
            ->whereNotNull('supplier_name')
            ->pluck('supplier_name')
            ->unique()
            ->values()
            ->implode(', ');

        $tagSeq = ProductionResult::query()
            ->where('work_order_id', $result->work_order_id)
            ->where('parent_part_id', $result->parent_part_id)
            ->where('id', '<=', $result->id)
            ->count() ?: 1;

        $tagNo = $consumedTags->isNotEmpty()
            ? $consumedTags->implode(', ')
            : (string) $tagSeq;

        $monthBox = str_pad((string) (($result->result_date ?? now())->format('m')), 2, '0', STR_PAD_LEFT);

        $selectedOperatorName = trim((string) $request->input('operator_name', ''));
        $operatorName = $selectedOperatorName !== ''
            ? $selectedOperatorName
            : ($result->reporter?->name ?? '-');

        return view('production.labels.wip', [
            'kind' => $kind,
            'qty' => $effectiveQty,
            'labelSheets' => $labelSheets,
            'packingType' => $packingType,
            'uom' => $result->uom ?: 'EA',
            'woNo' => $result->workOrder?->wo_no,
            'fgPartName' => $result->workOrder?->part?->part_name ?? $result->parentPart?->part_name,
            'partNumber' => $result->parentPart?->part_number,
            'partName' => $result->parentPart?->part_name,
            'processName' => $result->process?->process_name ?? $result->parentPart?->part_name,
            'stepFraction' => $stepFraction,
            'invoiceNo' => $invoiceLabel,
            'supplierName' => $supplierLabel !== '' ? $supplierLabel : null,
            'invoiceCount' => $consumedInvoices->count(),
            'tagNo' => $tagNo,
            'operatorName' => $operatorName,
            'foremanName' => '-',
            'monthBox' => $monthBox,
            'machineName' => $result->machine?->machine_name,
            'shift' => $result->shift,
            'periodStart' => (string) $result->period_start,
            'periodEnd' => (string) $result->period_end,
            'resultDate' => $result->result_date?->toDateString(),
            'isBackfilled' => (bool) $result->is_backfilled,
            'qr' => QrSvg::make((string) json_encode([
                'type' => 'wip',
                'wo_id' => (int) $result->work_order_id,
                'part_id' => (int) $result->parent_part_id,
                'result_id' => (int) $result->id,
                'kind' => $kind,
            ]), 340, 0),
        ]);
    }

    /**
     * Cetak label NG akumulatif (akhir shift atau akhir Work Order).
     */
    public function printAccumulatedNg(Request $request)
    {
        Gate::authorize('viewAny', WorkOrder::class);

        $timezone = (string) ConfigMaster::getValue('SYSTEM', 'timezone', 'Asia/Jakarta');
        $date = (string) ($request->input('date') ?: now($timezone)->toDateString());
        $workOrderId = $request->input('work_order_id');
        $shift = $request->input('shift');
        $partId = $request->input('part_id');

        $query = ProductionResult::query()
            ->with(['workOrder.part', 'parentPart', 'process', 'machine', 'reporter'])
            ->whereDate('result_date', $date)
            ->where('qty_reject', '>', 0)
            ->when($workOrderId, fn ($q) => $q->where('work_order_id', (int) $workOrderId))
            ->when($shift, fn ($q) => $q->where('shift', (string) $shift))
            ->when($partId, fn ($q) => $q->where('parent_part_id', (int) $partId));

        $results = $query->orderBy('period_start')->orderBy('id')->get();
        abort_if($results->isEmpty(), 404, __('Tidak ada laporan reject (NG) yang dapat diakumulasikan.'));

        $totalNg = round((float) $results->sum('qty_reject'), 4);
        abort_if($totalNg <= 0, 404, __('Kuantitas reject total 0.'));

        $first = $results->first();
        $woNo = $first->workOrder?->wo_no;

        $monthBox = str_pad((string) (($first->result_date ?? now())->format('m')), 2, '0', STR_PAD_LEFT);

        $labelSheets = [
            [
                'label_number' => 1,
                'total_labels' => 1,
                'qty' => $totalNg,
                'packing_type' => 'REJECT',
                'is_remainder' => false,
            ],
        ];

        return view('production.labels.wip', [
            'kind' => 'ng',
            'qty' => $totalNg,
            'labelSheets' => $labelSheets,
            'packingType' => 'REJECT',
            'uom' => $first->uom ?: 'EA',
            'woNo' => $woNo,
            'fgPartName' => $first->workOrder?->part?->part_name ?? $first->parentPart?->part_name,
            'partNumber' => $first->parentPart?->part_number,
            'partName' => $first->parentPart?->part_name,
            'processName' => $first->process?->process_name ?? $first->parentPart?->part_name,
            'stepFraction' => null,
            'invoiceNo' => 'AKUMULASI NG',
            'supplierName' => null,
            'invoiceCount' => 1,
            'tagNo' => '1',
            'operatorName' => $first->reporter?->name ?? '-',
            'foremanName' => '-',
            'monthBox' => $monthBox,
            'machineName' => $first->machine?->machine_name,
            'shift' => $shift ?? $first->shift,
            'periodStart' => $results->min('period_start') ?? '',
            'periodEnd' => $results->max('period_end') ?? '',
            'resultDate' => $date,
            'isBackfilled' => false,
            'qr' => QrSvg::make((string) json_encode([
                'type' => 'wip',
                'wo_id' => (int) $first->work_order_id,
                'part_id' => (int) $first->parent_part_id,
                'result_id' => (int) $first->id,
                'kind' => 'ng',
                'accumulated' => true,
                'total_ng' => $totalNg,
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
