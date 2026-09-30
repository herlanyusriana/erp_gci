<?php

namespace App\Http\Controllers;

use App\Models\ConfigMaster;
use App\Models\Machine;
use App\Models\ProductionMaterialReceipt;
use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProductionReceiptController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', WorkOrder::class);

        $machines = Machine::query()
            ->where('is_active', true)
            ->orderBy('machine_name')
            ->get(['id', 'machine_code', 'machine_name']);
        $timezone = ConfigMaster::getValue('SYSTEM', 'timezone', 'Asia/Jakarta');
        $selectedMachineId = $request->input('machine_id');
        $date = $request->input('date') ?? now($timezone)->toDateString();
        $receipts = [];

        if ($selectedMachineId !== null) {
            $machineId = (int) $selectedMachineId;
            $receipts = ProductionMaterialReceipt::query()
                ->where('machine_id', $machineId)
                ->whereDate('received_at', $date)
                ->with(['part:id,part_number,part_name,model,size', 'receiver:id,name', 'materialIssueItem:id,invoice,supplier'])
                ->orderByDesc('received_at')
                ->get()
                ->map(fn (ProductionMaterialReceipt $r) => [
                    'id' => $r->id,
                    'tag' => $r->tag,
                    'part' => $r->part ? [
                        'part_number' => $r->part->part_number,
                        'part_name' => $r->part->part_name,
                        'model' => $r->part->model,
                        'size' => $r->part->size,
                    ] : null,
                    'invoice' => $r->materialIssueItem?->invoice,
                    'supplier' => $r->materialIssueItem?->supplier,
                    'receiver_name' => $r->receiver?->name,
                    'received_at' => $r->received_at?->format('H:i'),
                    'notes' => $r->notes,
                ])
                ->all();
        }

        return Inertia::render('Production/Receipt/Index', [
            'machines' => $machines,
            'selectedMachineId' => $selectedMachineId !== null ? (int) $selectedMachineId : null,
            'date' => $date,
            'receipts' => $receipts,
            'timezone' => $timezone,
        ]);
    }
}
