<?php

namespace App\Http\Controllers;

use App\Models\ConfigMaster;
use App\Models\MaterialIssue;
use App\Services\MaterialIssueSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Issue Out Monitoring — **satu baris per Work Order**.
 *
 * Satu WO bisa punya beberapa slip (release per material, issue susulan OPP).
 * Ringkasan per WO berisi akumulasi material, rasio issued/required, dan tanggal
 * pengeluaran terakhir. Detail per slip dibuka lewat modal.
 */
class MaterialIssueController extends Controller
{
    public function __construct(private MaterialIssueSummaryService $summaryService) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', MaterialIssue::class);

        $today = now((string) ConfigMaster::getValue('SYSTEM', 'timezone', 'Asia/Jakarta'))->toDateString();
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $filters = array_merge([
            'date_from' => $today,
            'date_to' => $today,
        ], $validated);

        $rows = $this->summaryService->byWorkOrder(
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
            $filters['search'] ?? null,
        );

        $summary = [
            'work_order_count' => count($rows),
            'slip_count' => array_sum(array_column($rows, 'slip_count')),
            'tag_count' => collect($rows)->sum(fn (array $row): int => count($row['materials'])),
        ];

        return Inertia::render('Outgoing/MaterialIssue/Index', [
            'rows' => $rows,
            'filters' => $filters,
            'summary' => $summary,
        ]);
    }

    /** Satu baris ringkasan WO untuk penyegaran realtime. */
    public function row(Request $request, int $workOrder): JsonResponse
    {
        Gate::authorize('viewAny', MaterialIssue::class);

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        return response()->json([
            'ok' => true,
            'data' => $this->summaryService->rowForWorkOrder(
                $workOrder,
                $validated['date_from'] ?? null,
                $validated['date_to'] ?? null,
            ),
        ]);
    }

    /** Detail slip per WO untuk modal (dipanggil lewat JSON). */
    public function detail(Request $request, int $workOrder): JsonResponse
    {
        Gate::authorize('viewAny', MaterialIssue::class);

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        return response()->json([
            'ok' => true,
            'data' => $this->summaryService->detailsForWorkOrder(
                $workOrder,
                $validated['date_from'] ?? null,
                $validated['date_to'] ?? null,
            ),
        ]);
    }

    public function show(MaterialIssue $materialIssue): Response
    {
        Gate::authorize('view', $materialIssue);

        $materialIssue->load([
            'workOrder.part',
            'issuer:id,name',
            'items.part:id,part_number,part_name',
            'items.workOrderItem:id,child_part_name,uom_rm',
        ]);

        return Inertia::render('Outgoing/MaterialIssue/Show', [
            'issue' => $materialIssue,
        ]);
    }

    public function print(MaterialIssue $materialIssue)
    {
        Gate::authorize('view', $materialIssue);

        $materialIssue->load([
            'workOrder.part',
            'issuer:id,name',
            'items.part:id,part_number,part_name',
            'items.workOrderItem:id,child_part_name,uom_rm,qty_required',
        ]);

        return view('material-issues.print', [
            'issue' => $materialIssue,
            'companyName' => ConfigMaster::getValue('SYSTEM', 'company_name', 'PT Geum Cheon Indo'),
        ]);
    }
}
