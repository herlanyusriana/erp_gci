<?php

namespace App\Http\Controllers;

use App\Models\ConfigMaster;
use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class IncomingQualityController extends Controller
{
    /**
     * Tampilan antrean Incoming Quality (QC Material Masuk).
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', IncomingArrival::class);

        $timezone = (string) ConfigMaster::getValue('SYSTEM', 'timezone', 'Asia/Jakarta');
        $status = $request->input('status', 'pending'); // pending | passed | all

        $arrivals = IncomingArrival::query()
            ->with([
                'supplier:id,supplier_code,supplier_name',
                'purchaseOrder:id,po_no',
                'items.part:id,part_number,part_name',
                'items.iqcInspector:id,name',
            ])
            ->when($status === 'pending', function ($q) {
                $q->where(function ($sub) {
                    $sub->whereHas('items', fn ($it) => $it->where('iqc_status', 'pending'))
                        ->orWhereDoesntHave('items');
                });
            })
            ->when($status === 'passed', function ($q) {
                $q->whereDoesntHave('items', fn ($it) => $it->where('iqc_status', 'pending'))
                    ->whereHas('items', fn ($it) => $it->where('iqc_status', 'passed'));
            })
            ->when($request->input('search'), function ($q, $search) {
                $q->where(function ($query) use ($search) {
                    $query->where('arrival_no', 'ilike', "%{$search}%")
                        ->orWhere('po_no', 'ilike', "%{$search}%")
                        ->orWhere('invoice_no', 'ilike', "%{$search}%")
                        ->orWhere('delivery_note_no', 'ilike', "%{$search}%")
                        ->orWhereHas('supplier', fn ($s) => $s->where('supplier_name', 'ilike', "%{$search}%"))
                        ->orWhereHas('items.part', fn ($p) => $p->where('part_number', 'ilike', "%{$search}%"));
                });
            })
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Quality/Incoming/Index', [
            'arrivals' => $arrivals,
            'filters' => [
                'status' => $status,
                'search' => $request->input('search', ''),
            ],
            'timezone' => $timezone,
        ]);
    }

    /**
     * Detail inspeksi per material untuk sebuah kedatangan.
     */
    public function show(IncomingArrival $arrival): Response
    {
        Gate::authorize('view', $arrival);

        $arrival->load([
            'supplier:id,supplier_code,supplier_name',
            'purchaseOrder:id,po_no',
            'items.part:id,part_number,part_name',
            'items.iqcInspector:id,name',
        ]);

        return Inertia::render('Quality/Incoming/Show', [
            'arrival' => $arrival,
        ]);
    }

    /**
     * Ceklis / Setujui IQC Pass untuk satu item material spesifik.
     */
    public function passItem(Request $request, IncomingArrivalItem $item): RedirectResponse
    {
        Gate::authorize('update', $item->arrival);

        $item->update([
            'iqc_status' => 'passed',
            'iqc_passed_at' => now(),
            'iqc_passed_by' => $request->user()?->id,
        ]);

        // Selaraskan juga status keseluruhan arrival jika semua item sudah pass
        $this->syncArrivalIqcStatus($item->arrival);

        $partName = $item->part?->part_number ?? $item->material_group ?? 'Material';

        return back()->with('success', __('Material :part disetujui IQC Pass.', ['part' => $partName]));
    }

    /**
     * Batalkan persetujuan IQC Pass untuk item material spesifik.
     */
    public function revertItem(Request $request, IncomingArrivalItem $item): RedirectResponse
    {
        Gate::authorize('update', $item->arrival);

        $item->update([
            'iqc_status' => 'pending',
            'iqc_passed_at' => null,
            'iqc_passed_by' => null,
        ]);

        $this->syncArrivalIqcStatus($item->arrival);

        $partName = $item->part?->part_number ?? $item->material_group ?? 'Material';

        return back()->with('success', __('Status IQC untuk :part dikembalikan ke pending.', ['part' => $partName]));
    }

    /**
     * Loloskan seluruh material sekaligus (Pass All).
     */
    public function passAll(Request $request, IncomingArrival $arrival): RedirectResponse
    {
        Gate::authorize('update', $arrival);

        $arrival->items()->where('iqc_status', '!=', 'passed')->update([
            'iqc_status' => 'passed',
            'iqc_passed_at' => now(),
            'iqc_passed_by' => $request->user()?->id,
        ]);

        $this->syncArrivalIqcStatus($arrival);

        return back()->with('success', __('Seluruh material kedatangan :number disetujui IQC Pass.', ['number' => $arrival->arrival_no]));
    }

    private function syncArrivalIqcStatus(IncomingArrival $arrival): void
    {
        $arrival->loadMissing('items');
        $hasPending = $arrival->items->contains(fn ($it) => $it->iqc_status !== 'passed');
        $arrival->update([
            'iqc_status' => $hasPending ? 'pending' : 'passed',
            'iqc_passed_at' => $hasPending ? null : now(),
            'iqc_passed_by' => $hasPending ? null : auth()->id(),
        ]);
    }
}
