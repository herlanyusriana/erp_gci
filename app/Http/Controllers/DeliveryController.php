<?php

namespace App\Http\Controllers;

use App\Models\ConfigMaster;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryItem;
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
        ]);

        return Inertia::render('Outgoing/Delivery/Show', [
            'delivery' => $delivery,
            'prepLocation' => $this->deliveryService->deliveryPrepLocationCode(),
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

        if ($delivery->isShipped()) {
            throw ValidationException::withMessages([
                'status' => __('Pengiriman yang sudah dikirim tidak dapat diubah.'),
            ]);
        }

        $validated = $this->validatePayload($request);

        DB::transaction(function () use ($delivery, $validated, $request) {
            $delivery->update([
                'customer_id' => $validated['customer_id'],
                'delivery_date' => $validated['delivery_date'],
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

        if ($delivery->isShipped()) {
            return back()->with('error', __('Pengiriman yang sudah dikirim tidak dapat dihapus.'));
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

    /** Tandai dokumen sudah terkirim. */
    public function ship(Delivery $delivery): RedirectResponse
    {
        Gate::authorize('update', $delivery);

        if ($delivery->isShipped()) {
            return back()->with('error', __('Pengiriman ini sudah ditandai terkirim.'));
        }

        if ($delivery->items()->count() === 0) {
            return back()->with('error', __('Tambahkan minimal satu barang sebelum mengirim.'));
        }

        $delivery->update([
            'status' => Delivery::STATUS_SHIPPED,
            'shipped_at' => now(),
        ]);

        return back()->with('success', __('Pengiriman ditandai terkirim.'));
    }

    public function print(Delivery $delivery)
    {
        Gate::authorize('view', $delivery);

        $delivery->load(['customer', 'items.part:id,part_number,part_name']);

        $companyName = (string) ConfigMaster::getValue('SYSTEM', 'company_name', 'PT. GEUM CHEON INDO');

        return view('deliveries.surat-jalan', compact('delivery', 'companyName'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'delivery_date' => ['required', 'date'],
            'truck_no' => ['nullable', 'string', 'max:50'],
            'driver_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.part_stock_id' => ['required', 'integer', 'exists:part_stocks,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
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

            DeliveryItem::create([
                'delivery_id' => $delivery->id,
                'part_stock_id' => $stock->id,
                'part_id' => $stock->part_id,
                'tag' => $stock->tag,
                'qty' => (float) $row['qty'],
                'qty_unit' => $stock->qty_unit,
                'origin_location_code' => $stock->location_code,
                'notes' => $row['notes'] ?? null,
            ]);
        }
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
