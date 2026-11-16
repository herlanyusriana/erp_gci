<?php

namespace App\Http\Controllers;

use App\Models\ConfigMaster;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\DeliveryScan;
use App\Services\DeliveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengiriman barang jadi per customer.
 *
 * Dokumen lahir dari picking list (satu customer = satu dokumen), bukan dibuat
 * manual. Operator memindai tag FG di area asal; stok dipotong saat barang
 * dipindah ke area pengiriman.
 */
class DeliveryController extends Controller
{
    public function __construct(private DeliveryService $deliveryService) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Delivery::class);

        $deliveries = Delivery::query()
            ->with(['customer:id,customer_code,customer_name'])
            ->withCount('items')
            ->when($request->input('search'), fn ($q, $s) => $q
                ->where(fn ($w) => $w
                    ->where('delivery_no', 'ilike', "%{$s}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('customer_name', 'ilike', "%{$s}%"))))
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('from'), fn ($q, $d) => $q->whereDate('delivery_date', '>=', $d))
            ->when($request->input('to'), fn ($q, $d) => $q->whereDate('delivery_date', '<=', $d))
            ->orderByDesc('delivery_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Outgoing/Delivery/Index', [
            'deliveries' => $deliveries,
            'filters' => $request->only('search', 'status', 'from', 'to'),
            'statuses' => Delivery::STATUSES,
        ]);
    }

    public function show(Delivery $delivery): Response
    {
        Gate::authorize('view', $delivery);

        $delivery->load([
            'customer',
            'items.part:id,part_number,part_name',
            'scans.scanner:id,name',
        ]);

        return Inertia::render('Outgoing/Delivery/Show', [
            'delivery' => $delivery,
            'prepLocation' => $this->deliveryService->deliveryPrepLocationCode(),
            'loading' => $this->deliveryService->loadingContext($delivery),
            'trolleys' => $this->deliveryService->availableTrolleys($this->preferredTrolleyKind($delivery)),
            'trolleyMasterEmpty' => $this->deliveryService->trolleyMasterIsEmpty(),
        ]);
    }

    public function destroy(Delivery $delivery): RedirectResponse
    {
        Gate::authorize('delete', $delivery);

        if ($delivery->isReleased()) {
            return back()->with('error', __('Pengiriman yang dokumennya sudah dirilis tidak dapat dihapus.'));
        }

        // Barang yang sudah dipindah (dan stoknya dipotong) dikembalikan dulu.
        if ($delivery->stockDeducted()) {
            $this->deliveryService->returnToOrigin($delivery);
        }

        $delivery->delete();

        return redirect()->route('deliveries.index')->with('success', __('Pengiriman dihapus.'));
    }

    /** Pindahkan seluruh tag ke area pengiriman. */
    public function pick(Delivery $delivery): RedirectResponse
    {
        Gate::authorize('update', $delivery);

        $this->deliveryService->moveToPreparation($delivery);

        return back()->with('success', __('Barang dipindah ke area pengiriman.'));
    }

    /** Kembalikan tag ke lokasi asal (batal picking). */
    public function returnToOrigin(Delivery $delivery): RedirectResponse
    {
        Gate::authorize('update', $delivery);

        $this->deliveryService->returnToOrigin($delivery);

        return back()->with('success', __('Barang dikembalikan ke lokasi asal.'));
    }

    /** Tahap 3 — QC menyatakan barang lolos. */
    public function passQc(Request $request, Delivery $delivery): RedirectResponse
    {
        Gate::authorize('update', $delivery);

        $validated = $request->validate([
            'qc_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->deliveryService->passQc(
            $delivery,
            $request->user()?->id,
            $validated['qc_notes'] ?? null,
        );

        return back()->with('success', __('QC lolos. Label pengiriman bisa dirilis.'));
    }

    /** Tahap 3 — QC menolak, picking harus diperbaiki. */
    public function rejectQc(Request $request, Delivery $delivery): RedirectResponse
    {
        Gate::authorize('update', $delivery);

        $validated = $request->validate([
            'qc_notes' => ['required', 'string', 'max:500'],
        ], [
            'qc_notes.required' => __('Isi alasan penolakan QC.'),
        ]);

        $this->deliveryService->rejectQc(
            $delivery,
            $request->user()?->id,
            $validated['qc_notes'],
        );

        return back()->with('success', __('QC menolak. Perbaiki picking lalu ulangi QC.'));
    }

    /** Pindai manual dari web (cadangan bila kamera APK tidak dipakai). */
    public function scan(Request $request, Delivery $delivery): RedirectResponse
    {
        Gate::authorize('update', $delivery);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:1000'],
            'qty' => ['nullable', 'numeric', 'min:0.0001'],
        ]);

        $this->deliveryService->recordScan(
            $delivery,
            $validated['code'],
            $request->user()?->id,
            isset($validated['qty']) ? (float) $validated['qty'] : null,
        );

        return back()->with('success', __('Hasil pindai tersimpan.'));
    }

    /** Hapus satu hasil pindai yang salah. */
    public function destroyScan(Delivery $delivery, DeliveryScan $scan): RedirectResponse
    {
        Gate::authorize('update', $delivery);

        abort_unless((int) $scan->delivery_id === (int) $delivery->id, 404);

        // Setelah pindah, stok sudah dipotong; hapus scan akan membuat selisih.
        if (! $delivery->isDraft()) {
            return back()->with('error', __('Scan hanya dapat dihapus sebelum barang dipindah ke area pengiriman.'));
        }

        $scan->delete();

        return back()->with('success', __('Hasil pindai dihapus.'));
    }

    /** Picking: centang/lepas centang "FG sudah diambil". */
    public function toggleItemPicked(Delivery $delivery, DeliveryItem $item): RedirectResponse
    {
        Gate::authorize('update', $delivery);

        abort_unless((int) $item->delivery_id === (int) $delivery->id, 404);

        if (! $delivery->isDraft()) {
            return back()->with('error', __('Centang pengambilan hanya bisa diubah pada tahap picking.'));
        }

        $item->update(['is_picked' => ! $item->is_picked]);

        return back();
    }

    /** Simpan No. PEN / AJU / Invoice sebelum submit. */
    public function updateDocuments(Request $request, Delivery $delivery): RedirectResponse
    {
        Gate::authorize('update', $delivery);

        $validated = $request->validate([
            'pen_no' => ['nullable', 'string', 'max:255'],
            'aju_no' => ['nullable', 'string', 'max:255'],
            'invoice_no' => ['nullable', 'string', 'max:255'],
        ]);

        $this->deliveryService->saveDocuments($delivery, $validated, $request->user()?->id);

        return back()->with('success', __('Dokumen pengiriman disimpan.'));
    }

    /** Rilis data pengiriman ke CEISA (syarat input No. PEN/AJU). */
    public function releaseCeisa(Delivery $delivery): RedirectResponse
    {
        Gate::authorize('update', $delivery);

        $this->deliveryService->releaseCeisa($delivery, request()->user()?->id);

        return back()->with('success', __('Data CEISA dirilis. Isi No. PEN dan No. AJU.'));
    }

    /** Submit outgoing: barang keluar, stok berkurang, dokumen bisa dicetak. */
    public function submitOutgoing(Delivery $delivery): RedirectResponse
    {
        Gate::authorize('update', $delivery);

        $this->deliveryService->submitOutgoing($delivery);

        return back()->with('success', __('Outgoing tercatat. Stok sudah berkurang.'));
    }

    /** Label pengiriman per tag FG: dicetak setelah barang di area pengiriman. */
    public function printLabel(Delivery $delivery)
    {
        Gate::authorize('view', $delivery);

        abort_unless($delivery->labelPrintable(), 403, __('Label dicetak setelah barang dipindah ke area pengiriman.'));

        $delivery->load(['customer', 'items.part:id,part_number,part_name', 'scans']);

        $companyName = (string) ConfigMaster::getValue('SYSTEM', 'company_name', 'PT. GEUM CHEON INDO');

        return view('deliveries.label', [
            'delivery' => $delivery,
            'companyName' => $companyName,
            'labels' => $this->deliveryService->labelEntries($delivery),
        ]);
    }

    /** Surat jalan — hanya setelah dokumen dirilis. */
    public function print(Delivery $delivery)
    {
        Gate::authorize('view', $delivery);

        abort_unless($delivery->documentsPrintable(), 403, __('Dokumen dicetak setelah OQC lolos.'));

        $delivery->load(['customer', 'items.part:id,part_number,part_name']);

        $companyName = (string) ConfigMaster::getValue('SYSTEM', 'company_name', 'PT. GEUM CHEON INDO');

        return view('deliveries.surat-jalan', compact('delivery', 'companyName'));
    }

    /** Invoice — hanya setelah dokumen dirilis. */
    public function printInvoice(Delivery $delivery)
    {
        Gate::authorize('view', $delivery);

        abort_unless($delivery->documentsPrintable(), 403, __('Dokumen dicetak setelah OQC lolos.'));

        $delivery->load(['customer', 'items.part:id,part_number,part_name']);

        $companyName = (string) ConfigMaster::getValue('SYSTEM', 'company_name', 'PT. GEUM CHEON INDO');

        return view('deliveries.invoice', compact('delivery', 'companyName'));
    }

    /** Packing list — hanya setelah dokumen dirilis. */
    public function printPackingList(Delivery $delivery)
    {
        Gate::authorize('view', $delivery);

        abort_unless($delivery->documentsPrintable(), 403, __('Dokumen dicetak setelah OQC lolos.'));

        $delivery->load(['customer', 'items.part:id,part_number,part_name']);

        $companyName = (string) ConfigMaster::getValue('SYSTEM', 'company_name', 'PT. GEUM CHEON INDO');

        return view('deliveries.packing-list', compact('delivery', 'companyName'));
    }

    /**
     * Jenis troli yang paling cocok untuk pengiriman ini, diambil dari model
     * part FG pertama (mis. "CompBase" -> compbase).
     */
    private function preferredTrolleyKind(Delivery $delivery): ?string
    {
        $model = $delivery->items()
            ->join('parts', 'parts.id', '=', 'delivery_items.part_id')
            ->value('parts.model');

        $model = strtolower(trim((string) $model));
        if ($model === '') {
            return null;
        }

        foreach (['reinforce', 'backplate', 'compbase'] as $kind) {
            if (str_contains($model, $kind)) {
                return $kind;
            }
        }

        return null;
    }
}
