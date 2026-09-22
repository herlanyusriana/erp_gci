<?php

namespace App\Http\Controllers;

use App\Models\Part;
use App\Models\PartPrice;
use App\Models\PartSubstitute;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Rules\PartTypeRule;
use App\Rules\UomCode;
use App\Support\UomCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PurchaseOrder::class);

        $purchaseOrders = PurchaseOrder::query()
            ->with(['supplier:id,supplier_code,supplier_name'])
            ->withCount('items')
            ->when($request->input('search'), fn ($q, $s) => $q
                ->where('po_no', 'ilike', "%{$s}%")
                ->orWhereHas('supplier', fn ($w) => $w->where('supplier_name', 'ilike', "%{$s}%")))
            ->orderByDesc('created_at')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Incoming/PurchaseOrder/Index', [
            'purchaseOrders' => $purchaseOrders,
            'filters' => $request->only('search'),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', PurchaseOrder::class);

        return Inertia::render('Incoming/PurchaseOrder/Form', [
            'purchaseOrder' => null,
            'suppliers' => Supplier::select('id', 'supplier_code', 'supplier_name')->orderBy('supplier_name')->get(),
            'parts' => Part::select('id', 'part_number', 'part_name')
                ->where('is_active', true)
                ->whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))
                ->orderBy('part_number')->get(),
            'uomCodes' => UomCatalog::codes(),
            'supplierParts' => PartSubstitute::supplierParts(),
            'activePrices' => $this->activePrices(),
        ]);
    }

    public function show(PurchaseOrder $purchaseOrder): Response
    {
        Gate::authorize('view', $purchaseOrder);

        $purchaseOrder->load(['supplier', 'items.part']);

        return Inertia::render('Incoming/PurchaseOrder/Show', [
            'purchaseOrder' => $purchaseOrder,
        ]);
    }

    public function edit(PurchaseOrder $purchaseOrder): Response
    {
        Gate::authorize('update', $purchaseOrder);

        $purchaseOrder->load(['items']);

        return Inertia::render('Incoming/PurchaseOrder/Form', [
            'purchaseOrder' => $purchaseOrder,
            'suppliers' => Supplier::select('id', 'supplier_code', 'supplier_name')->orderBy('supplier_name')->get(),
            'parts' => Part::select('id', 'part_number', 'part_name')
                ->where('is_active', true)
                ->whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))
                ->orderBy('part_number')->get(),
            'uomCodes' => UomCatalog::codes(),
            'supplierParts' => PartSubstitute::supplierParts(),
            'activePrices' => $this->activePrices(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'po_no' => ['required', 'string', 'max:255', 'unique:purchase_orders,po_no'],
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'po_date' => ['nullable', 'date'],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'status' => ['required', 'in:draft,confirmed,cancelled'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.part_id' => ['required', 'exists:parts,id', PartTypeRule::notFg()],
            'items.*.qty' => ['required', 'numeric', 'min:0'],
            'items.*.unit' => ['nullable', 'string', 'max:20', UomCode::optional()],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        $po = DB::transaction(function () use ($data, $request) {
            $po = PurchaseOrder::create([
                'po_no' => $data['po_no'],
                'supplier_id' => $data['supplier_id'],
                'status' => $data['status'],
                'po_date' => $data['po_date'] ?? null,
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            foreach ($data['items'] as $item) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'part_id' => $item['part_id'] ?? null,
                    'qty' => $item['qty'],
                    'unit' => $item['unit'] ?? null,
                    'price' => $item['price'] ?? null,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            return $po;
        });

        return redirect()->route('purchase-orders.show', $po)->with('success', __('Purchase order created.'));
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $data = $request->validate([
            'po_no' => ['required', 'string', 'max:255', "unique:purchase_orders,po_no,{$purchaseOrder->id}"],
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'po_date' => ['nullable', 'date'],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'status' => ['required', 'in:draft,confirmed,cancelled'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.part_id' => ['required', 'exists:parts,id', PartTypeRule::notFg()],
            'items.*.qty' => ['required', 'numeric', 'min:0'],
            'items.*.unit' => ['nullable', 'string', 'max:20', UomCode::optional()],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($purchaseOrder, $data, $request) {
            $purchaseOrder->update([
                'po_no' => $data['po_no'],
                'supplier_id' => $data['supplier_id'],
                'status' => $data['status'],
                'po_date' => $data['po_date'] ?? null,
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'updated_by' => $request->user()?->id,
            ]);

            $purchaseOrder->items()->delete();
            foreach ($data['items'] as $item) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $purchaseOrder->id,
                    'part_id' => $item['part_id'] ?? null,
                    'qty' => $item['qty'],
                    'unit' => $item['unit'] ?? null,
                    'price' => $item['price'] ?? null,
                    'notes' => $item['notes'] ?? null,
                ]);
            }
        });

        return redirect()->route('purchase-orders.show', $purchaseOrder)->with('success', __('Purchase order updated.'));
    }

    public function destroy(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $purchaseOrder->delete();

        return redirect()->route('purchase-orders.index')->with('success', __('Purchase order deleted.'));
    }

    /**
     * Harga aktif per (supplier, part): baris `part_prices` dengan `valid_from`
     * terbaru yang tidak melewati hari ini.
     *
     * @return list<array{supplier_id:int, part_id:int, price:float, currency:string}>
     */
    private function activePrices(): array
    {
        return PartPrice::query()
            ->where('is_active', true)
            ->whereDate('valid_from', '<=', now()->toDateString())
            ->orderBy('valid_from')
            ->get(['supplier_id', 'part_id', 'price', 'currency'])
            ->groupBy(fn (PartPrice $r) => $r->supplier_id.'|'.$r->part_id)
            ->map(fn ($group) => [
                'supplier_id' => (int) $group->last()->supplier_id,
                'part_id' => (int) $group->last()->part_id,
                'price' => (float) $group->last()->price,
                'currency' => (string) $group->last()->currency,
            ])
            ->values()
            ->all();
    }
}
