<?php

namespace App\Http\Controllers;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalContainer;
use App\Models\IncomingArrivalContainerInspection;
use App\Models\IncomingArrivalItem;
use App\Models\IncomingReceive;
use App\Models\PartSubstitute;
use App\Exports\ArrivalDetailExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
                if (!$part || trim((string) $part->size) === '') {
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
            $group = trim((string) $item['material_group']);
            $size = strtoupper(trim((string) $item['size']));

            $mapping = PartSubstitute::query()
                ->where('is_active', true)
                ->where('supplier_id', $supplierId)
                ->whereRaw('UPPER(TRIM(material_group)) = ?', [strtoupper($group)])
                ->whereHas('substitutePart', fn ($q) => $q->whereRaw('UPPER(size) = ?', [$size]))
                ->with('substitutePart:id,size,uom_id')
                ->first();

            if (!$mapping || !$mapping->substitutePart) {
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
            ->where('is_local', false)
            ->with(['supplier:id,supplier_code,supplier_name'])
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

    public function create(): Response
    {
        Gate::authorize('create', IncomingArrival::class);

        return Inertia::render('Incoming/Arrival/Form', [
            'arrival' => null,
            'suppliers' => \App\Models\Supplier::select('id', 'supplier_code', 'supplier_name')->orderBy('supplier_name')->get(),
            'truckings' => \App\Models\TruckingCompany::select('id', 'company_code', 'company_name')->where('is_active', true)->orderBy('company_name')->get(),
            'materialOptions' => $this->materialOptions(),
            'purchaseOrders' => \App\Models\PurchaseOrder::select('id', 'po_no', 'supplier_id')->orderByDesc('created_at')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', IncomingArrival::class);

        $validated = $request->validate([
            'invoice_no' => ['nullable', 'string', 'max:255'],
            'invoice_date' => ['nullable', 'date'],
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'trucking_company_id' => ['nullable', 'exists:trucking_companies,id'],
            'purchase_order_id' => ['nullable', 'exists:purchase_orders,id'],
            'vessel' => ['nullable', 'string', 'max:255'],
            'etd' => ['nullable', 'date'],
            'eta' => ['nullable', 'date'],
            'eta_gci' => ['nullable', 'date'],
            'bill_of_lading' => ['nullable', 'string', 'max:255'],
            'pen_no' => ['nullable', 'string', 'max:255'],
            'pen_date' => ['nullable', 'date'],
            'aju_no' => ['nullable', 'string', 'max:255'],
            'price_term' => ['nullable', 'string', 'max:50'],
            'hs_code' => ['nullable', 'string', 'max:255'],
            'port_of_loading' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string'],
            'containers' => ['nullable', 'array'],
            'containers.*.container_no' => ['required_with:containers', 'string', 'max:255'],
            'containers.*.seal_code' => ['nullable', 'string', 'max:255'],
            'containers.*.size' => ['nullable', 'string', 'max:50'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.material_group' => ['required', 'string', 'max:255'],
            'items.*.size' => ['required', 'string', 'max:100'],
            'items.*.qty_goods' => ['required', 'numeric', 'min:0'],
            'items.*.unit_goods' => ['nullable', 'string', 'max:20'],
            'items.*.qty_bundle' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_bundle' => ['nullable', 'string', 'max:20'],
            'items.*.weight_nett' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_weight' => ['nullable', 'string', 'max:20'],
            'items.*.weight_gross' => ['nullable', 'numeric', 'min:0'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.total_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.is_foc' => ['sometimes', 'boolean'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        $validated['items'] = $this->resolveMaterialItems($validated['items'], (int) $validated['supplier_id']);

        $arrival = DB::transaction(function () use ($validated, $request) {
            $arrival = IncomingArrival::create([
                'arrival_no' => IncomingArrival::generateArrivalNo(),
                'invoice_no' => $validated['invoice_no'] ?? null,
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
                'created_by' => $request->user()?->id,
            ]);

            foreach ($validated['containers'] ?? [] as $container) {
                IncomingArrivalContainer::create([
                    'arrival_id' => $arrival->id,
                    'container_no' => $container['container_no'],
                    'seal_code' => $container['seal_code'] ?? null,
                    'size' => $container['size'] ?? null,
                ]);
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

    public function show(IncomingArrival $arrival): Response
    {
        Gate::authorize('view', $arrival);

        $arrival->load([
            'supplier',
            'trucking',
            'purchaseOrder',
            'items.part',
            'items.receives',
            'containers.inspection',
        ]);

        $pending = collect($arrival->items)->map(function ($item) {
            $received = (float) $item->receives
                ->sum(fn (IncomingReceive $r) => (float) ($r->net_weight ?? $r->weight ?? $r->qty ?? 0));

            return [
                'item' => $item,
                'received' => $received,
                'remaining' => max(0, (float) $item->weight_nett - $received),
            ];
        });

        return Inertia::render('Incoming/Arrival/Show', [
            'arrival' => $arrival,
            'pending' => $pending,
        ]);
    }

    public function edit(IncomingArrival $arrival): Response
    {
        Gate::authorize('update', $arrival);

        $arrival->load(['supplier', 'items', 'containers']);

        return Inertia::render('Incoming/Arrival/Form', [
            'arrival' => $arrival,
            'suppliers' => \App\Models\Supplier::select('id', 'supplier_code', 'supplier_name')->orderBy('supplier_name')->get(),
            'truckings' => \App\Models\TruckingCompany::select('id', 'company_code', 'company_name')->where('is_active', true)->orderBy('company_name')->get(),
            'materialOptions' => $this->materialOptions(),
            'purchaseOrders' => \App\Models\PurchaseOrder::select('id', 'po_no', 'supplier_id')->orderByDesc('created_at')->get(),
        ]);
    }

    public function update(Request $request, IncomingArrival $arrival): RedirectResponse
    {
        Gate::authorize('update', $arrival);

        $validated = $request->validate([
            'invoice_no' => ['nullable', 'string', 'max:255'],
            'invoice_date' => ['nullable', 'date'],
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'trucking_company_id' => ['nullable', 'exists:trucking_companies,id'],
            'purchase_order_id' => ['nullable', 'exists:purchase_orders,id'],
            'vessel' => ['nullable', 'string', 'max:255'],
            'etd' => ['nullable', 'date'],
            'eta' => ['nullable', 'date'],
            'eta_gci' => ['nullable', 'date'],
            'bill_of_lading' => ['nullable', 'string', 'max:255'],
            'pen_no' => ['nullable', 'string', 'max:255'],
            'pen_date' => ['nullable', 'date'],
            'aju_no' => ['nullable', 'string', 'max:255'],
            'price_term' => ['nullable', 'string', 'max:50'],
            'hs_code' => ['nullable', 'string', 'max:255'],
            'port_of_loading' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string'],
            'status' => ['required', 'in:pending,completed,cancelled'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer', 'exists:incoming_arrival_items,id'],
            'items.*.material_group' => ['required', 'string', 'max:255'],
            'items.*.size' => ['required', 'string', 'max:100'],
            'items.*.qty_goods' => ['required', 'numeric', 'min:0'],
            'items.*.unit_goods' => ['nullable', 'string', 'max:20'],
            'items.*.qty_bundle' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_bundle' => ['nullable', 'string', 'max:20'],
            'items.*.weight_nett' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_weight' => ['nullable', 'string', 'max:20'],
            'items.*.weight_gross' => ['nullable', 'numeric', 'min:0'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.total_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.is_foc' => ['nullable', 'boolean'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        $validated['items'] = $this->resolveMaterialItems($validated['items'], (int) $validated['supplier_id']);
        $validated['updated_by'] = $request->user()?->id;
        DB::transaction(function () use ($arrival, $validated) {
            $arrival->update(\Arr::only($validated, [
                'invoice_no', 'invoice_date', 'supplier_id', 'trucking_company_id', 'purchase_order_id',
                'vessel', 'etd', 'eta', 'eta_gci',
                'bill_of_lading', 'pen_no', 'pen_date', 'aju_no',
                'price_term', 'hs_code', 'port_of_loading', 'country', 'currency',
                'notes', 'status', 'updated_by',
            ]));

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

        $filename = 'Commercial-Invoice-' . str_replace(['/', '\\'], '-', ($arrival->invoice_no ?: $arrival->arrival_no)) . '.pdf';

        $pdf = Pdf::loadView('arrivals.invoice', [
            'arrival' => $arrival,
            'browserPrint' => false,
        ])->setPaper('a4', 'portrait');

        return $pdf->download($filename);
    }
}