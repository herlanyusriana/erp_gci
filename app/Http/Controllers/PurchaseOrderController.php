<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
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
            'suppliers' => \App\Models\Supplier::select('id', 'supplier_code', 'supplier_name')->orderBy('supplier_name')->get(),
            'parts' => \App\Models\Part::select('id', 'part_number', 'part_name')
                ->where('is_active', true)
                ->whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))
                ->orderBy('part_number')->get(),
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
            'items.*.part_id' => ['required', 'exists:parts,id', \App\Rules\PartTypeRule::notFg()],
            'items.*.qty' => ['required', 'numeric', 'min:0'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
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
            'items.*.part_id' => ['required', 'exists:parts,id', \App\Rules\PartTypeRule::notFg()],
            'items.*.qty' => ['required', 'numeric', 'min:0'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
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
}