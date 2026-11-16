<?php

namespace App\Http\Controllers;

use App\Models\ConfigMaster;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\DeliveryScan;
use App\Models\PartStock;
use App\Services\DeliveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengiriman barang jadi.
 *
 * Dokumen dibuat manual per kejadian. Tag FG disarankan FIFO tetapi boleh
 * diganti. Stok total tidak berubah saat dipindah ke area pengiriman; hanya
 * lokasi fisik yang berubah.
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

    public function create(Request $request): Response
    {
        Gate::authorize('create', Delivery::class);

        return Inertia::render('Outgoing/Delivery/Form', [
            'delivery' => null,
            'customers' => $this->activeCustomers(),
            'parts' => $this->deliveryService->deliverableParts(),
            'suggestedTags' => $this->deliveryService->availableTags(
                $request->input('part_id') !== null ? (int) $request->input('part_id') : null,
            ),
            'prepLocation' => $this->deliveryService->deliveryPrepLocationCode(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Delivery::class);

        $validated = $this->validatePayload($request);

        $delivery = DB::transaction(function () use ($validated, $request) {
            $delivery = Delivery::create([
                'delivery_no' => Delivery::generateDeliveryNo(now()),
                'customer_id' => $validated['customer_id'],
                'delivery_date' => $validated['delivery_date'],
                'status' => Delivery::STATUS_DRAFT,
                'invoice_no' => $validated['invoice_no'] ?? null,
                'truck_no' => $validated['truck_no'] ?? null,
                'driver_name' => $validated['driver_name'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            $this->syncItems($delivery, $validated['items']);

            return $delivery;
        });

        return redirect()
            ->route('deliveries.show', $delivery)
            ->with('success', __('Pengiriman :no dibuat.', ['no' => $delivery->delivery_no]));
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

    public function edit(Delivery $delivery): Response
    {
        Gate::authorize('update', $delivery);

        $delivery->load(['items.part:id,part_number,part_name']);

        return Inertia::render('Outgoing/Delivery/Form', [
            'delivery' => $delivery,
            'customers' => $this->activeCustomers(),
            'parts' => $this->deliveryService->deliverableParts(),
            'suggestedTags' => $this->deliveryService->availableTags(),
            'prepLocation' => $this->deliveryService->deliveryPrepLocationCode(),
        ]);
    }

    public function update(Request $request, Delivery $delivery): RedirectResponse
    {
        Gate::authorize('update', $delivery);

        if (! $delivery->isDraft()) {
            throw ValidationException::withMessages([
                'status' => __('Pengiriman hanya bisa diubah pada tahap Delivery Order.'),
            ]);
        }

        $validated = $this->validatePayload($request);

        DB::transaction(function () use ($delivery, $validated, $request) {
            $delivery->update([
                'customer_id' => $validated['customer_id'],
                'delivery_date' => $validated['delivery_date'],
                'invoice_no' => $validated['invoice_no'] ?? null,
                'truck_no' => $validated['truck_no'] ?? null,
                'driver_name' => $validated['driver_name'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'updated_by' => $request->user()?->id,
            ]);

            // Baris diganti utuh agar tag yang dihapus benar-benar hilang.
            $delivery->items()->delete();
            $this->syncItems($delivery, $validated['items']);

            // Setelah baris berubah, status kembali ke draft.
            if (! $delivery->isDraft()) {
                $delivery->update(['status' => Delivery::STATUS_DRAFT]);
            }
        });

        return redirect()
            ->route('deliveries.show', $delivery)
            ->with('success', __('Pengiriman diperbarui.'));
    }

    public function destroy(Delivery $delivery): RedirectResponse
    {
        Gate::authorize('delete', $delivery);

        if ($delivery->isReleased()) {
            return back()->with('error', __('Pengiriman yang dokumennya sudah dirilis tidak dapat dihapus.'));
        }

        // Barang yang sudah dipindah dikembalikan ke lokasi asal sebelum dokumen hilang.
        if ($delivery->isPicked()) {
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
        ]);

        $this->deliveryService->recordScan($delivery, $validated['code'], $request->user()?->id);

        return back()->with('success', __('Hasil pindai tersimpan.'));
    }

    /** Hapus satu hasil pindai yang salah. */
    public function destroyScan(Delivery $delivery, DeliveryScan $scan): RedirectResponse
    {
        Gate::authorize('update', $delivery);

        abort_unless((int) $scan->delivery_id === (int) $delivery->id, 404);

        if (! $delivery->isDraft() && ! $delivery->isPicked()) {
            return back()->with('error', __('Scan hanya dapat dihapus pada tahap pindah ke area pengiriman.'));
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

        if ($delivery->isReleased()) {
            return back()->with('error', __('Outgoing sudah disubmit; dokumen tidak dapat diubah.'));
        }

        $delivery->update([
            'pen_no' => $validated['pen_no'] ?? null,
            'aju_no' => $validated['aju_no'] ?? null,
            'invoice_no' => $validated['invoice_no'] ?? null,
            'updated_by' => $request->user()?->id,
        ]);

        return back()->with('success', __('Dokumen pengiriman disimpan.'));
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

        $delivery->load(['customer', 'items.part:id,part_number,part_name']);

        $companyName = (string) ConfigMaster::getValue('SYSTEM', 'company_name', 'PT. GEUM CHEON INDO');

        return view('deliveries.label', compact('delivery', 'companyName'));
    }

    /** Surat jalan — hanya setelah dokumen dirilis. */
    public function print(Delivery $delivery)
    {
        Gate::authorize('view', $delivery);

        abort_unless($delivery->documentsReleased(), 403, __('Dokumen belum dirilis.'));

        $delivery->load(['customer', 'items.part:id,part_number,part_name']);

        $companyName = (string) ConfigMaster::getValue('SYSTEM', 'company_name', 'PT. GEUM CHEON INDO');

        return view('deliveries.surat-jalan', compact('delivery', 'companyName'));
    }

    /** Invoice — hanya setelah dokumen dirilis. */
    public function printInvoice(Delivery $delivery)
    {
        Gate::authorize('view', $delivery);

        abort_unless($delivery->documentsReleased(), 403, __('Dokumen belum dirilis.'));

        $delivery->load(['customer', 'items.part:id,part_number,part_name']);

        $companyName = (string) ConfigMaster::getValue('SYSTEM', 'company_name', 'PT. GEUM CHEON INDO');

        return view('deliveries.invoice', compact('delivery', 'companyName'));
    }

    /** Packing list — hanya setelah dokumen dirilis. */
    public function printPackingList(Delivery $delivery)
    {
        Gate::authorize('view', $delivery);

        abort_unless($delivery->documentsReleased(), 403, __('Dokumen belum dirilis.'));

        $delivery->load(['customer', 'items.part:id,part_number,part_name']);

        $companyName = (string) ConfigMaster::getValue('SYSTEM', 'company_name', 'PT. GEUM CHEON INDO');

        return view('deliveries.packing-list', compact('delivery', 'companyName'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'delivery_date' => ['required', 'date'],
            'invoice_no' => ['nullable', 'string', 'max:255'],
            'truck_no' => ['nullable', 'string', 'max:50'],
            'driver_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.part_stock_id' => ['required', 'integer', 'exists:part_stocks,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ], [
            'items.required' => __('Tambahkan minimal satu barang.'),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function syncItems(Delivery $delivery, array $items): void
    {
        $seen = [];

        foreach ($items as $row) {
            $stockId = (int) $row['part_stock_id'];

            if (isset($seen[$stockId])) {
                throw ValidationException::withMessages([
                    'items' => __('Tag yang sama tidak boleh dipilih dua kali.'),
                ]);
            }
            $seen[$stockId] = true;

            $stock = PartStock::query()->with('part:id,part_number,part_name,part_type_id', 'part.partType:id,code')
                ->find($stockId);

            if ($stock === null) {
                throw ValidationException::withMessages([
                    'items' => __('Tag tidak ditemukan.'),
                ]);
            }

            $this->deliveryService->assertDeliverable($stock, (float) $row['qty']);

            $price = isset($row['price']) && $row['price'] !== null ? (float) $row['price'] : null;
            $qty = (float) $row['qty'];

            DeliveryItem::create([
                'delivery_id' => $delivery->id,
                'part_stock_id' => $stock->id,
                'part_id' => $stock->part_id,
                'tag' => $stock->tag,
                'qty' => $qty,
                'qty_unit' => $stock->qty_unit,
                'origin_location_code' => $stock->location_code,
                'price' => $price,
                'total_price' => $price !== null ? round($qty * $price, 4) : null,
                'notes' => $row['notes'] ?? null,
            ]);
        }
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

    /**
     * @return list<array{id: int, customer_code: string, customer_name: string, address: string|null}>
     */
    private function activeCustomers(): array
    {
        return Customer::query()
            ->where('is_active', true)
            ->orderBy('customer_name')
            ->get(['id', 'customer_code', 'customer_name', 'address'])
            ->map(fn (Customer $customer): array => [
                'id' => (int) $customer->id,
                'customer_code' => $customer->customer_code,
                'customer_name' => $customer->customer_name,
                'address' => $customer->address,
            ])
            ->all();
    }
}
