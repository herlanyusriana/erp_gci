<?php

namespace App\Http\Controllers;

use App\Models\ConfigMaster;
use App\Models\MaterialIssue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MaterialIssueController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', MaterialIssue::class);

        $issues = MaterialIssue::query()
            ->with([
                'workOrder:id,wo_no,part_id',
                'workOrder.part:id,part_number,part_name',
                'issuer:id,name',
            ])
            ->withCount('items')
            ->when($request->input('search'), fn ($q, $s) => $q
                ->where('issue_no', 'ilike', "%{$s}%")
                ->orWhereHas('workOrder', fn ($w) => $w->where('wo_no', 'ilike', "%{$s}%")))
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Outgoing/MaterialIssue/Index', [
            'issues' => $issues,
            'filters' => $request->only('search'),
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
        ]);

        return view('material-issues.print', [
            'issue' => $materialIssue,
            'companyName' => ConfigMaster::getValue('SYSTEM', 'company_name', 'PT Geum Cheon Indo'),
        ]);
    }
}
