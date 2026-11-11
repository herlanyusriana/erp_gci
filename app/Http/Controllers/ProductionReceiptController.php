<?php

namespace App\Http\Controllers;

use App\Models\ConfigMaster;
use App\Models\Machine;
use App\Models\WorkOrder;
use App\Services\WipInventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * WIP Inventory: kartu stok material per mesin.
 *
 * Dua tab: WIP & FG (barang setengah jadi dan barang jadi) serta Raw Material.
 * Beginning + Received − Issued = Ending untuk rentang tanggal yang dipilih.
 */
class ProductionReceiptController extends Controller
{
    public const TABS = [WipInventoryService::CATEGORY_WIP_FG, WipInventoryService::CATEGORY_MATERIAL];

    public function __construct(
        private WipInventoryService $wipInventory,
    ) {}

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

        $tab = (string) $request->input('tab', WipInventoryService::CATEGORY_WIP_FG);
        $tab = in_array($tab, self::TABS, true) ? $tab : WipInventoryService::CATEGORY_WIP_FG;

        $today = now($timezone)->toDateString();
        $from = (string) ($request->input('from') ?: Carbon::parse($today, $timezone)->startOfMonth()->toDateString());
        $to = (string) ($request->input('to') ?: $today);

        // Rentang terbalik ditukar agar laporan tetap masuk akal.
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $rows = $this->wipInventory->forRange($from, $to, $machineId, $tab);

        return Inertia::render('Production/Receipt/Index', [
            'machines' => $machines,
            'selectedMachineId' => $machineId,
            'tab' => $tab,
            'tabs' => self::TABS,
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'timezone' => $timezone,
        ]);
    }

    /**
     * Rincian pergerakan satu mesin + part pada satu rentang, dipecah per nota.
     */
    public function detail(Request $request, int $machine, int $part): Response
    {
        Gate::authorize('viewAny', WorkOrder::class);

        $timezone = (string) ConfigMaster::getValue('SYSTEM', 'timezone', 'Asia/Jakarta');
        $today = now($timezone)->toDateString();

        // `date` dipertahankan untuk tautan lama (satu hari).
        $from = (string) ($request->input('from') ?: $request->input('date') ?: $today);
        $to = (string) ($request->input('to') ?: $from);

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return Inertia::render('Production/Receipt/Detail', [
            'detail' => $this->wipInventory->detail($machine, $part, $from, $to),
            'from' => $from,
            'to' => $to,
            'timezone' => $timezone,
        ]);
    }
}
