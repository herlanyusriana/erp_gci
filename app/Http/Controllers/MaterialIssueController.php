<?php

namespace App\Http\Controllers;

use App\Models\ConfigMaster;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MaterialIssueController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', MaterialIssue::class);

        $today = now('Asia/Jakarta')->toDateString();
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'operator_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'in:'.implode(',', MaterialIssue::STATUSES)],
        ]);
        $filters = array_merge([
            'date_from' => $today,
            'date_to' => $today,
        ], $validated);

        $query = $this->filteredQuery($filters);
        $issues = (clone $query)
            ->with([
                'workOrder:id,wo_no,part_id',
                'workOrder.part:id,part_number,part_name',
                'issuer:id,name',
            ])
            ->withCount('items')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $issueIds = (clone $query)->select('material_issues.id');
        $summaryItems = MaterialIssueItem::query()->whereIn('material_issue_id', $issueIds);
        $qtyByUom = (clone $summaryItems)
            ->selectRaw("COALESCE(uom, '—') AS uom, SUM(qty) AS qty")
            ->groupBy('uom')
            ->orderBy('uom')
            ->get()
            ->map(fn (MaterialIssueItem $item): array => [
                'uom' => (string) $item->uom,
                'qty' => (float) $item->qty,
            ])
            ->values();

        $summary = [
            'issue_count' => (clone $query)->count('material_issues.id'),
            'work_order_count' => (clone $query)->distinct('work_order_id')->count('work_order_id'),
            'tag_count' => (clone $summaryItems)->whereNotNull('tag')->distinct('tag')->count('tag'),
            'qty_by_uom' => $qtyByUom,
        ];

        $operatorIds = MaterialIssue::query()
            ->whereNotNull('issued_by')
            ->distinct()
            ->pluck('issued_by');
        $operators = User::query()
            ->where('is_active', true)
            ->whereIn('id', $operatorIds)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Outgoing/MaterialIssue/Index', [
            'issues' => $issues,
            'filters' => $filters,
            'summary' => $summary,
            'operators' => $operators,
            'statuses' => MaterialIssue::STATUSES,
        ]);
    }

    /**
     * Apply all index filters to one reusable query scope.
     *
     * @param  array{search?: string|null, date_from?: string|null, date_to?: string|null, operator_id?: int|string|null, status?: string|null}  $filters
     */
    private function filteredQuery(array $filters): Builder
    {
        return MaterialIssue::query()
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('issue_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('issue_date', '<=', $date))
            ->when($filters['operator_id'] ?? null, fn (Builder $query, int|string $operatorId) => $query->where('issued_by', $operatorId))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $term = "%{$search}%";

                $query->where(function (Builder $searchQuery) use ($term): void {
                    $searchQuery
                        ->where('issue_no', 'ilike', $term)
                        ->orWhere('received_by', 'ilike', $term)
                        ->orWhereHas('issuer', fn (Builder $issuer) => $issuer->where('name', 'ilike', $term))
                        ->orWhereHas('workOrder', function (Builder $workOrder) use ($term): void {
                            $workOrder
                                ->where('wo_no', 'ilike', $term)
                                ->orWhereHas('part', fn (Builder $part) => $part
                                    ->where('part_number', 'ilike', $term)
                                    ->orWhere('part_name', 'ilike', $term));
                        })
                        ->orWhereHas('items', function (Builder $item) use ($term): void {
                            $item
                                ->where('tag', 'ilike', $term)
                                ->orWhere('invoice', 'ilike', $term)
                                ->orWhere('supplier', 'ilike', $term)
                                ->orWhereHas('part', fn (Builder $part) => $part
                                    ->where('part_number', 'ilike', $term)
                                    ->orWhere('part_name', 'ilike', $term));
                        });
                });
            });
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
        ]);

        return view('material-issues.print', [
            'issue' => $materialIssue,
            'companyName' => ConfigMaster::getValue('SYSTEM', 'company_name', 'PT Geum Cheon Indo'),
        ]);
    }
}
