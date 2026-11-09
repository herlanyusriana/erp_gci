<?php

namespace App\Http\Controllers;

use App\Exports\ArrivalDetailExport;
use App\Models\IncomingArrival;
use App\Models\IncomingArrivalContainer;
use App\Models\IncomingArrivalItem;
use App\Models\Part;
use App\Models\PartSubstitute;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\TruckingCompany;
use App\Rules\UomCode;
use App\Support\PurchaseOrderLimit;
use App\Support\UomCatalog;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class IncomingArrivalController extends Controller
{
    /** Supplier -> material group -> size, read dynamically from part_substitutes. part_id is resolved internally. */
    private function materialOptions(): array
    {
        return PartSubstitute::query()
            ->where('is_active', true)
            ->whereNotNull('supplier_id')
            ->whereNotNull('material_group')
            ->whereRaw("NULLIF(TRIM(material_group), '') IS NOT NULL")
            ->with(['substitutePart:id,part_number,part_name,size,uom_id', 'substitutePart.uom:id,code'])
            ->get()
            ->map(function (PartSubstitute $mapping) {
                $part = $mapping->substitutePart;
                if (! $part || trim((string) $part->size) === '') {
                    return null;
                }

                return [
                    'supplier_id' => (int) $mapping->supplier_id,
                    'group' => trim((string) $mapping->material_group),
                    'size' => trim((string) $part->size),
                    'part_id' => (int) $part->id,
                    'unit' => $part->uom?->code,
                ];
            })
            ->filter()
            ->unique(fn (array $row) => $row['supplier_id'].'|'.strtoupper($row['group']).'|'.strtoupper($row['size']))
            ->values()
            ->all();
    }

    private function resolveMaterialItems(array $items, int $supplierId): array
    {
        return collect($items)->map(function (array $item, int $index) use ($supplierId) {
            // Jika part_id sudah disertakan langsung (misal dari PO auto-fill), validasi ke part yang bersangkutan
            if (! empty($item['part_id'])) {
                $part = Part::find((int) $item['part_id']);
                if ($part) {
                    $item['material_group'] = $item['material_group'] ?? $part->part_number;
                    $item['size'] = $item['size'] ?? ($part->size ?: 'STD');

                    return $item;
                }
            }

            $group = trim((string) ($item['material_group'] ?? ''));
            $size = strtoupper(trim((string) ($item['size'] ?? '')));

            $mapping = PartSubstitute::query()
                ->where('is_active', true)
                ->where('supplier_id', $supplierId)
                ->whereRaw('UPPER(TRIM(material_group)) = ?', [strtoupper($group)])
                ->whereHas('substitutePart', fn ($q) => $q->whereRaw('UPPER(size) = ?', [$size]))
                ->with('substitutePart:id,size,uom_id')
                ->first();

            if (! $mapping || ! $mapping->substitutePart) {
                throw ValidationException::withMessages([
                    "items.{$index}.size" => __('Size tidak tersedia untuk supplier dan material group yang dipilih.'),
                ]);
            }

            $item['part_id'] = (int) $mapping->substitute_part_id;
            $item['material_group'] = $group;
            $item['size'] = trim((string) $mapping->substitutePart->size);

            return $item;
        })->all();
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', IncomingArrival::class);

        $arrivals = IncomingArrival::query()
            ->with(['supplier:id,supplier_code,supplier_name', 'items.receives'])
            ->withCount('items')
            ->when($request->input('search'), fn ($q, $s) => $q
                ->where('arrival_no', 'ilike', "%{$s}%")
                ->orWhere('invoice_no', 'ilike', "%{$s}%")
                ->orWhere('transaction_no', 'ilike', "%{$s}%")
                ->orWhereHas('supplier', fn ($w) => $w->where('supplier_name', 'ilike', "%{$s}%")))
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Incoming/Arrival/Index', [
            'arrivals' => $arrivals,
            'filters' => $request->only('search', 'status'),
        ]);
    }

    /**
     * Mengambil daftar PO aktif yang masih memiliki sisa kuantitas belum tiba,
     * atau menyertakan PO saat ini jika dalam mode edit.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function activePurchaseOrders(?int $currentPoId = null)
    {
        $pos = PurchaseOrder::query()
            ->with(['items.part.uom', 'supplier:id,source,supplier_name'])
            ->whereNotIn('status', ['cancelled'])
            ->select('id', 'po_no', 'supplier_id', 'status', 'po_date', 'expected_date')
            ->orderByDesc('created_at')
            ->get();

        // Hitung total kuantitas arrival yang sudah dicatat per PO
        $arrivalTotals = DB::table('incoming_arrival_items as iai')
            ->join('incoming_arrivals as ia', 'ia.id', '=', 'iai.arrival_id')
            ->whereNull('ia.deleted_at')
            ->whereNull('iai.deleted_at')
            ->whereNotNull('ia.purchase_order_id')
            ->groupBy('ia.purchase_order_id')
            ->selectRaw('ia.purchase_order_id, SUM(iai.qty_goods) as total_arrived')
            ->pluck('total_arrived', 'purchase_order_id');

        return $pos->filter(function (PurchaseOrder $po) use ($arrivalTotals, $currentPoId) {
            // Selalu sertakan PO milik arrival yang sedang diedit
            if ($currentPoId !== null && (int) $po->id === (int) $currentPoId) {
                return true;
            }

            if ($po->status === 'completed') {
                return false;
            }

            $totalPoQty = (float) $po->items->sum('qty');
            $arrived = (float) ($arrivalTotals[$po->id] ?? 0);
            $remaining = $totalPoQty - $arrived;

            // Jika total PO 0 (belum ada item), tetap izinkan agar bisa diisi
            return $totalPoQty <= 0 || $remaining > 1e-6;
        })->map(function (PurchaseOrder $po) use ($arrivalTotals) {
            $totalPoQty = (float) $po->items->sum('qty');
            $arrived = (float) ($arrivalTotals[$po->id] ?? 0);
            $remaining = max(0.0, $totalPoQty - $arrived);

            return [
                'id' => $po->id,
                'po_no' => $po->po_no,
                'supplier_id' => $po->supplier_id,
                'status' => $po->status,
                'total_qty' => round($totalPoQty, 4),
                'arrived_qty' => round($arrived, 4),
                'remaining_qty' => round($remaining, 4),
                'supplier' => $po->supplier ? [
                    'id' => $po->supplier->id,
                    'supplier_name' => $po->supplier->supplier_name,
                    'source' => $po->supplier->source,
                ] : null,
                'items' => $po->items->map(fn ($it) => [
                    'id' => $it->id,
                    'part_id' => $it->part_id,
                    'size' => $it->size,
                    'qty' => (float) $it->qty,
                    'unit' => $it->unit ?? $it->part?->uom?->code,
                    'price' => (float) $it->price,
                    'notes' => $it->notes,
                    'part' => $it->part ? [
                        'id' => $it->part->id,
                        'part_number' => $it->part->part_number,
                        'part_name' => $it->part->part_name,
                        'size' => $it->part->size,
                    ] : null,
                ])->values()->all(),
            ];
        })->values();
    }

    public function create(): Response
    {
        Gate::authorize('create', IncomingArrival::class);

        return Inertia::render('Incoming/Arrival/Form', [
            'arrival' => null,
            'suppliers' => Supplier::select('id', 'supplier_code', 'supplier_name', 'source')->where('is_active', true)->orderBy('supplier_name')->get(),
            'truckings' => TruckingCompany::select('id', 'company_code', 'company_name')->where('is_active', true)->orderBy('company_name')->get(),
            'materialOptions' => $this->materialOptions(),
            'purchaseOrders' => $this->activePurchaseOrders(),
            'uomCodes' => UomCatalog::codes(),
            'packingUnits' => UomCatalog::packingUnits(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', IncomingArrival::class);

        $isLocal = filter_var($request->input('is_local', false), FILTER_VALIDATE_BOOLEAN);

        $rules = [
            'is_local' => ['nullable', 'boolean'],
            'invoice_no' => ['nullable', 'string', 'max:255'],
            'delivery_note_no' => ['nullable', 'string', 'max:255'],
            'invoice_date' => ['nullable', 'date'],
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'purchase_order_id' => ['nullable', 'exists:purchase_orders,id'],
            'pen_no' => ['nullable', 'string', 'max:255'],
            'pen_date' => ['nullable', 'date'],
            'aju_no' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.material_group' => ['nullable', 'string', 'max:255'],
            'items.*.part_id' => ['nullable', 'integer', 'exists:parts,id'],
            'items.*.size' => ['nullable', 'string', 'max:100'],
            'items.*.qty_goods' => ['required', 'numeric', 'min:0'],
            'items.*.unit_goods' => ['nullable', 'string', 'max:20', UomCode::optional()],
            'items.*.qty_bundle' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_bundle' => ['nullable', 'string', 'max:20', Rule::in(UomCatalog::packingUnits())],
            'items.*.weight_nett' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_weight' => ['nullable', 'string', 'max:20', UomCode::optional()],
            'items.*.weight_gross' => ['nullable', 'numeric', 'min:0'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.total_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.is_foc' => ['sometimes', 'boolean'],
            'items.*.notes' => ['nullable', 'string'],
        ];

        if (! $isLocal) {
            $rules += [
                'trucking_company_id' => ['nullable', 'exists:trucking_companies,id'],
                'vessel' => ['nullable', 'string', 'max:255'],
                'etd' => ['nullable', 'date'],
                'eta' => ['nullable', 'date'],
                'eta_gci' => ['nullable', 'date'],
                'bill_of_lading' => ['nullable', 'string', 'max:255'],
                'price_term' => ['nullable', 'string', 'max:50'],
                'hs_code' => ['nullable', 'string', 'max:255'],
                'port_of_loading' => ['nullable', 'string', 'max:255'],
                'country' => ['nullable', 'string', 'max:255'],
                'currency' => ['nullable', 'string', 'max:10'],
                'containers' => ['nullable', 'array'],
                'containers.*.container_no' => ['required_with:containers', 'string', 'max:255'],
                'containers.*.seal_code' => ['nullable', 'string', 'max:255'],
                'containers.*.size' => ['nullable', 'string', 'max:50'],
            ];
        }

        $validated = $request->validate($rules);

        $validated['items'] = $this->resolveMaterialItems($validated['items'], (int) $validated['supplier_id']);

        $this->assertWithinPurchaseOrderLimit($validated['purchase_order_id'] ?? null, $validated['items']);

        $arrival = DB::transaction(function () use ($validated, $isLocal, $request) {
            $arrival = IncomingArrival::create([
                'arrival_no' => IncomingArrival::generateArrivalNo(),
                'is_local' => $isLocal,
                'invoice_no' => $validated['invoice_no'] ?? null,
                'delivery_note_no' => $validated['delivery_note_no'] ?? null,
                'invoice_date' => $validated['invoice_date'] ?? null,
                'supplier_id' => $validated['supplier_id'] ?? null,
                'trucking_company_id' => $validated['trucking_company_id'] ?? null,
                'purchase_order_id' => $validated['purchase_order_id'] ?? null,
                'vessel' => $validated['vessel'] ?? null,
                'etd' => $validated['etd'] ?? null,
                'eta' => $validated['eta'] ?? null,
                'eta_gci' => $validated['eta_gci'] ?? null,
                'bill_of_lading' => $validated['bill_of_lading'] ?? null,
                'pen_no' => $validated['pen_no'] ?? null,
                'pen_date' => $validated['pen_date'] ?? null,
                'aju_no' => $validated['aju_no'] ?? null,
                'price_term' => $validated['price_term'] ?? null,
                'hs_code' => $validated['hs_code'] ?? null,
                'port_of_loading' => $validated['port_of_loading'] ?? null,
                'country' => $validated['country'] ?? null,
                'currency' => $validated['currency'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => 'pending',
                'iqc_status' => 'pending',
                'created_by' => $request->user()?->id,
            ]);

            if (! $isLocal) {
                foreach ($validated['containers'] ?? [] as $container) {
                    IncomingArrivalContainer::create([
                        'arrival_id' => $arrival->id,
                        'container_no' => $container['container_no'],
                        'seal_code' => $container['seal_code'] ?? null,
                        'size' => $container['size'] ?? null,
                    ]);
                }
            }

            foreach ($validated['items'] as $item) {
                IncomingArrivalItem::create([
                    'arrival_id' => $arrival->id,
                    'part_id' => $item['part_id'] ?? null,
                    'material_group' => $item['material_group'] ?? null,
                    'size' => $item['size'] ?? null,
                    'qty_goods' => $item['qty_goods'],
                    'unit_goods' => $item['unit_goods'] ?? null,
                    'qty_bundle' => $item['qty_bundle'] ?? null,
                    'unit_bundle' => $item['unit_bundle'] ?? null,
                    'weight_nett' => $item['weight_nett'] ?? null,
                    'unit_weight' => $item['unit_weight'] ?? null,
                    'weight_gross' => $item['weight_gross'] ?? null,
                    'price' => $item['price'] ?? null,
                    'total_price' => $item['total_price'] ?? null,
                    'is_foc' => (bool) ($item['is_foc'] ?? false),
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            return $arrival;
        });

        return redirect()->route('incoming-arrivals.show', $arrival)->with('success', __('Arrival created.'));
    }

    /**
     * Pastikan total kedatangan tidak melebihi qty baris PO.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function assertWithinPurchaseOrderLimit(?int $purchaseOrderId, array $items, bool $excludeExistingItems = false): void
    {
        if ($purchaseOrderId === null) {
            return;
        }

        $errors = [];

        foreach ($items as $index => $item) {
            $partId = isset($item['part_id']) ? (int) $item['part_id'] : null;
            $basis = PurchaseOrderLimit::arrivalBasis(
                (float) ($item['qty_goods'] ?? 0),
                $item['unit_goods'] ?? null,
                (float) ($item['weight_nett'] ?? 0),
                $item['unit_weight'] ?? null,
            );

            if ($partId === null || $basis === null) {
                continue;
            }

            [$qty, $unit] = $basis;
            $excludeId = $excludeExistingItems && ! empty($item['id']) ? (int) $item['id'] : null;
            $limit = PurchaseOrderLimit::arrivalLimit($purchaseOrderId, $partId, $unit, $excludeId);

            if ($limit === null || $qty <= $limit['remaining'] + 1e-9) {
                continue;
            }

            $errors["items.$index.qty_goods"] = __('Qty kedatangan (:qty :unit) melebihi qty PO (:po :unit). Sisa yang bisa dikedatangkan :remaining :unit.', [
                'qty' => $qty,
                'unit' => $unit,
                'po' => $limit['po_qty'],
                'remaining' => $limit['remaining'],
            ]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function show(IncomingArrival $arrival): Response
    {
        Gate::authorize('view', $arrival);

        $arrival->load([
            'supplier',
            'trucking',
            'purchaseOrder',
            'items.part',
            'containers.inspection',
        ]);

        $pending = collect($arrival->items)->map(function ($item) {
            $received = (float) $item->receives()
                ->sum(DB::raw('COALESCE(net_weight, weight, qty, 0)'));

            return [
                'item' => $item,
                'received' => $received,
                'remaining' => max(0, (float) ($item->weight_nett ?: $item->qty_goods) - $received),
            ];
        });

        return Inertia::render('Incoming/Arrival/Show', [
            'arrival' => $arrival,
            'pending' => $pending,
            'weightUnit' => UomCatalog::WEIGHT,
        ]);
    }

    public function edit(IncomingArrival $arrival): Response
    {
        Gate::authorize('update', $arrival);

        $arrival->load(['supplier', 'items', 'containers']);

        return Inertia::render('Incoming/Arrival/Form', [
            'arrival' => $arrival,
            'suppliers' => Supplier::select('id', 'supplier_code', 'supplier_name', 'source')->where('is_active', true)->orderBy('supplier_name')->get(),
            'truckings' => TruckingCompany::select('id', 'company_code', 'company_name')->where('is_active', true)->orderBy('company_name')->get(),
            'materialOptions' => $this->materialOptions(),
            'purchaseOrders' => $this->activePurchaseOrders($arrival->purchase_order_id),
            'uomCodes' => UomCatalog::codes(),
            'packingUnits' => UomCatalog::packingUnits(),
        ]);
    }

    public function update(Request $request, IncomingArrival $arrival): RedirectResponse
    {
        Gate::authorize('update', $arrival);

        $isLocal = filter_var($request->input('is_local', $arrival->is_local), FILTER_VALIDATE_BOOLEAN);

        $rules = [
            'is_local' => ['nullable', 'boolean'],
            'invoice_no' => ['nullable', 'string', 'max:255'],
            'delivery_note_no' => ['nullable', 'string', 'max:255'],
            'invoice_date' => ['nullable', 'date'],
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'purchase_order_id' => ['nullable', 'exists:purchase_orders,id'],
            'pen_no' => ['nullable', 'string', 'max:255'],
            'pen_date' => ['nullable', 'date'],
            'aju_no' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['required', 'in:pending,completed,cancelled'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer', 'exists:incoming_arrival_items,id'],
            'items.*.material_group' => ['nullable', 'string', 'max:255'],
            'items.*.part_id' => ['nullable', 'integer', 'exists:parts,id'],
            'items.*.size' => ['nullable', 'string', 'max:100'],
            'items.*.qty_goods' => ['required', 'numeric', 'min:0'],
            'items.*.unit_goods' => ['nullable', 'string', 'max:20', UomCode::optional()],
            'items.*.qty_bundle' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_bundle' => ['nullable', 'string', 'max:20', Rule::in(UomCatalog::packingUnits())],
            'items.*.weight_nett' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_weight' => ['nullable', 'string', 'max:20', UomCode::optional()],
            'items.*.weight_gross' => ['nullable', 'numeric', 'min:0'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.total_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.is_foc' => ['nullable', 'boolean'],
            'items.*.notes' => ['nullable', 'string'],
        ];

        if (! $isLocal) {
            $rules += [
                'trucking_company_id' => ['nullable', 'exists:trucking_companies,id'],
                'vessel' => ['nullable', 'string', 'max:255'],
                'etd' => ['nullable', 'date'],
                'eta' => ['nullable', 'date'],
                'eta_gci' => ['nullable', 'date'],
                'bill_of_lading' => ['nullable', 'string', 'max:255'],
                'price_term' => ['nullable', 'string', 'max:50'],
                'hs_code' => ['nullable', 'string', 'max:255'],
                'port_of_loading' => ['nullable', 'string', 'max:255'],
                'country' => ['nullable', 'string', 'max:255'],
                'currency' => ['nullable', 'string', 'max:10'],
            ];
        }

        $validated = $request->validate($rules);

        $validated['items'] = $this->resolveMaterialItems($validated['items'], (int) $validated['supplier_id']);
        $validated['updated_by'] = $request->user()?->id;

        $this->assertWithinPurchaseOrderLimit($validated['purchase_order_id'] ?? null, $validated['items'], true);
        DB::transaction(function () use ($arrival, $validated, $isLocal) {
            $updateFields = [
                'is_local' => $isLocal,
                'invoice_no' => $validated['invoice_no'] ?? null,
                'delivery_note_no' => $validated['delivery_note_no'] ?? null,
                'invoice_date' => $validated['invoice_date'] ?? null,
                'supplier_id' => $validated['supplier_id'] ?? null,
                'purchase_order_id' => $validated['purchase_order_id'] ?? null,
                'pen_no' => $validated['pen_no'] ?? null,
                'pen_date' => $validated['pen_date'] ?? null,
                'aju_no' => $validated['aju_no'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => $validated['status'],
                'updated_by' => $validated['updated_by'],
            ];

            if (! $isLocal) {
                $updateFields += [
                    'trucking_company_id' => $validated['trucking_company_id'] ?? null,
                    'vessel' => $validated['vessel'] ?? null,
                    'etd' => $validated['etd'] ?? null,
                    'eta' => $validated['eta'] ?? null,
                    'eta_gci' => $validated['eta_gci'] ?? null,
                    'bill_of_lading' => $validated['bill_of_lading'] ?? null,
                    'price_term' => $validated['price_term'] ?? null,
                    'hs_code' => $validated['hs_code'] ?? null,
                    'port_of_loading' => $validated['port_of_loading'] ?? null,
                    'country' => $validated['country'] ?? null,
                    'currency' => $validated['currency'] ?? null,
                ];
            }

            $arrival->update($updateFields);

            foreach ($validated['items'] as $item) {
                $payload = \Arr::except($item, ['id']);
                $payload['arrival_id'] = $arrival->id;
                $arrival->items()->updateOrCreate(
                    ['id' => $item['id'] ?? null],
                    $payload,
                );
            }
        });

        return redirect()->route('incoming-arrivals.show', $arrival)->with('success', __('Arrival updated.'));
    }

    public function destroy(IncomingArrival $arrival): RedirectResponse
    {
        Gate::authorize('delete', $arrival);

        $arrival->delete();

        return redirect()->route('incoming-arrivals.index')->with('success', __('Arrival deleted.'));
    }

    /**
     * Save container inspection results.
     */
    public function inspectContainer(Request $request, IncomingArrivalContainer $container): RedirectResponse
    {
        Gate::authorize('update', $container->arrival);

        $validated = $request->validate([
            'status' => ['required', 'in:ok,damage'],
            'seal_condition' => ['nullable', 'in:ok,broken,missing'],
            'container_condition' => ['nullable', 'string', 'max:255'],
            'driver_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $container->inspection()->updateOrCreate(
            ['arrival_container_id' => $container->id],
            [
                'status' => $validated['status'],
                'seal_condition' => $validated['seal_condition'] ?? null,
                'container_condition' => $validated['container_condition'] ?? null,
                'driver_name' => $validated['driver_name'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'inspected_by' => $request->user()?->id,
                'inspected_at' => now(),
            ]
        );

        return back()->with('success', __('Inspection saved.'));
    }

    /**
     * Export arrival detail to Excel.
     */
    public function export(IncomingArrival $arrival)
    {
        Gate::authorize('view', $arrival);

        return Excel::download(new ArrivalDetailExport($arrival), 'arrival-'.$arrival->arrival_no.'.xlsx');
    }

    /**
     * Printable commercial invoice + packing list (browser, tombol Cetak).
     */
    public function invoice(IncomingArrival $arrival)
    {
        Gate::authorize('view', $arrival);

        $arrival->load(['supplier', 'trucking', 'purchaseOrder', 'items.part', 'containers']);

        return view('arrivals.invoice', [
            'arrival' => $arrival,
            'browserPrint' => true,
        ]);
    }

    /**
     * Export arrival invoice (commercial invoice + packing list) to PDF.
     */
    public function pdf(IncomingArrival $arrival)
    {
        Gate::authorize('view', $arrival);

        $arrival->load(['supplier', 'trucking', 'purchaseOrder', 'items.part', 'containers']);

        $filename = 'Commercial-Invoice-'.str_replace(['/', '\\'], '-', ($arrival->invoice_no ?: $arrival->arrival_no)).'.pdf';

        $pdf = Pdf::loadView('arrivals.invoice', [
            'arrival' => $arrival,
            'browserPrint' => false,
        ])->setPaper('a4', 'portrait');

        return $pdf->download($filename);
    }
}
