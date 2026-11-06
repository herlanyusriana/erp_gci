<?php

namespace App\Http\Controllers;

use App\Models\ConfigMaster;
use App\Models\IncomingArrival;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class IncomingQualityController extends Controller
{
    /**
     * Tampilan antrean Incoming Quality (QC Material Masuk).
     * Format sesuai arahan operasional:
     * Tanggal Arrival | Supp Name | PO No | Item List | Ceklis IQC Pass
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
                'iqcInspector:id,name',
            ])
            ->when($status === 'pending', fn ($q) => $q->where('iqc_status', 'pending'))
            ->when($status === 'passed', fn ($q) => $q->where('iqc_status', 'passed'))
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
            ->orderByRaw("CASE WHEN iqc_status = 'pending' THEN 0 ELSE 1 END")
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
     * Berikan persetujuan IQC Pass pada kedatangan material.
     */
    public function pass(Request $request, IncomingArrival $arrival): RedirectResponse
    {
        Gate::authorize('update', $arrival);

        $arrival->update([
            'iqc_status' => 'passed',
            'iqc_passed_at' => now(),
            'iqc_passed_by' => $request->user()?->id,
        ]);

        return back()->with('success', __('Kedatangan :number disetujui IQC Pass.', ['number' => $arrival->arrival_no]));
    }

    /**
     * Batalkan persetujuan IQC Pass jika terjadi kekeliruan klik.
     */
    public function revert(Request $request, IncomingArrival $arrival): RedirectResponse
    {
        Gate::authorize('update', $arrival);

        $arrival->update([
            'iqc_status' => 'pending',
            'iqc_passed_at' => null,
            'iqc_passed_by' => null,
        ]);

        return back()->with('success', __('Status IQC untuk :number dikembalikan ke pending.', ['number' => $arrival->arrival_no]));
    }
}
