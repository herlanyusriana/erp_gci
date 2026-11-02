<?php

namespace App\Http\Controllers;

use App\Models\ConfigMaster;
use App\Models\Machine;
use App\Models\WorkOrder;
use App\Services\WipInventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * WIP Inventory: kartu stok WIP per mesin.
 *
 * Beginning + Received (scan APK) − Issued (konsumsi BOM dari production result)
 * = End Stock. Saldo awal menyambung dari hari-hari sebelumnya.
 */
class ProductionReceiptController extends Controller
{
    public function __construct(private WipInventoryService $wipInventory) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', WorkOrder::class);

        $machines = Machine::query()
            ->where('is_active', true)
            ->orderBy('machine_name')
            ->get(['id', 'machine_code', 'machine_name']);

        $timezone = (string) ConfigMaster::getValue('SYSTEM', 'timezone', 'Asia/Jakarta');
        $selectedMachineId = $request->input('machine_id');
        $machineId = $selectedMachineId !== null && $selectedMachineId !== ''
            ? (int) $selectedMachineId
            : null;
        $date = (string) ($request->input('date') ?: now($timezone)->toDateString());

        $rows = $this->wipInventory->forDate($date, $machineId);

        return Inertia::render('Production/Receipt/Index', [
            'machines' => $machines,
            'selectedMachineId' => $machineId,
            'date' => $date,
            'rows' => $rows,
            'timezone' => $timezone,
        ]);
    }
}
