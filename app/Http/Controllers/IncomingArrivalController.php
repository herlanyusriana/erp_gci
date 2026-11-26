<?php

namespace App\Http\Controllers;

use App\Exports\ArrivalDetailExport;
use App\Models\BomItem;
use App\Models\Customer;
use App\Models\IncomingArrival;
use App\Models\IncomingArrivalContainer;
use App\Models\IncomingArrivalItem;
use App\Models\Part;
use App\Models\PartSubstitute;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Subcontractor;
use App\Models\Supplier;
use App\Models\TruckingCompany;
use App\Rules\UomCode;
use App\Support\DocumentNumber;
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
            ->with(['substitutePart:id,part_number,part_name,model,size,uom_id', 'substitutePart.uom:id,code'])
            ->get()
            ->map(function (PartSubstitute $mapping) {
                $part = $mapping->substitutePart;
                if (! $part) {
                    return null;
                }

                $group = trim((string) ($mapping->material_group ?? ''));
                $size = trim((string) ($mapping->size ?: ($part->size ?: 'STD')));

                return [
                    'supplier_id' => (int) $mapping->supplier_id,
                    'group' => $group ?: $part->part_number,
                    'size' => $size,
                    'part_id' => (int) $part->id,
                    'part_number' => $part->part_number,
                    'part_name' => $part->part_name,
                    'model' => $part->model,
                    'unit' => $part->uom?->code,
                ];
            })
            ->filter()
            ->unique(fn (array $row) => $row['supplier_id'].'|'.$row['part_id'])
            ->values()
            ->all();
    }

    private function resolveMaterialItems(array $items, int $supplierId): array
    {
        $partIds = collect($items)
            ->pluck('part_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $partsById = $partIds === []
            ? collect()
            : Part::query()->whereIn('id', $partIds)->get()->keyBy('id');

        $supplierSubstitutes = PartSubstitute::query()
            ->where('is_active', true)
            ->where('supplier_id', $supplierId)
            ->with('substitutePart:id,size,uom_id')
            ->orderByRaw("NULLIF(TRIM(COALESCE(material_group, '')), '') IS NULL")
            ->get();

        $mappingByPartId = $supplierSubstitutes
            ->groupBy('substitute_part_id')
            ->map(fn (Collection $rows) => $rows->first());

        $mappingByGroupSize = [];
        foreach ($supplierSubstitutes as $sub) {
            if (! $sub->substitutePart) {
                continue;
            }
            $key = strtoupper(trim((string) ($sub->material_group ?? ''))).'|'.strtoupper((string) ($sub->substitutePart->size ?? ''));
            $mappingByGroupSize[$key] ??= $sub;
        }

        return collect($items)->map(function (array $item, int $index) use ($partsById, $mappingByPartId, $mappingByGroupSize) {
            // Jika part_id sudah disertakan langsung (misal dari PO auto-fill),
            // group & size tetap diambil dari pemetaan substitute supplier, bukan
            // dari part number. Part number bukan "kelompok material".
            if (! empty($item['part_id'])) {
                $part = $partsById->get((int) $item['part_id']);
                if ($part) {
                    $mapping = $mappingByPartId->get($part->id);

                    $group = trim((string) ($mapping?->material_group ?? ''));
                    $mappedSize = trim((string) ($mapping?->size ?? ''));

                    $item['material_group'] = $group !== '' ? $group : $part->part_number;
                    $item['size'] = $item['size'] ?? ($mappedSize !== '' ? $mappedSize : ($part->size ?: 'STD'));

                    return $item;
                }
            }

            $group = trim((string) ($item['material_group'] ?? ''));
            $size = strtoupper(trim((string) ($item['size'] ?? '')));

            $mapping = $mappingByGroupSize[strtoupper($group).'|'.$size] ?? null;

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

    /**
     * ID part aktif di BOM yang diizinkan untuk kedatangan OSP GCI (Subcon) atau OSP Customer (FREE_ISSUE/OSP).
     *
     * @return list<int>
     */
    private function ospPartIds(string $arrivalType): array
    {
        if ($arrivalType === IncomingArrival::TYPE_OSP_GCI) {
            $fromSource = BomItem::query()
                ->where('is_active', true)
                ->whereHas('bom', fn ($b) => $b->where('is_active', true))
                ->whereNotNull('child_part_id')
                ->whereRaw("UPPER(COALESCE(source, '')) = 'SUBCON'")
                ->pluck('child_part_id');

            $fromMachine = BomItem::query()
                ->where('is_active', true)
                ->whereHas('bom', fn ($b) => $b->where('is_active', true))
                ->whereNotNull('parent_part_id')
                ->whereHas('machine', fn ($m) => $m->whereRaw(
                    "UPPER(COALESCE(machine_code, '')) = 'SUBCON' OR LOWER(COALESCE(machine_name, '')) LIKE '%subcon%'"
                ))
                ->pluck('parent_part_id');

            return $fromSource
                ->merge($fromMachine)
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        if ($arrivalType === IncomingArrival::TYPE_OSP_CUSTOMER) {
            return BomItem::query()
                ->where('is_active', true)
                ->whereHas('bom', fn ($b) => $b->where('is_active', true))
                ->whereNotNull('child_part_id')
                ->whereRaw("UPPER(COALESCE(source, '')) = 'FREE_ISSUE' OR UPPER(COALESCE(special_code, '')) = 'OSP'")
                ->pluck('child_part_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        return [];
    }

    /**
     * Daftar opsi part untuk form kedatangan OSP sesuai sub-jenisnya.
     *
     * @return list<array<string, mixed>>
     */
    private function ospPartOptions(string $arrivalType): array
    {
        $ids = $this->ospPartIds($arrivalType);
        if ($ids === []) {
            return [];
        }

        return Part::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->with(['uom:id,code', 'partType:id,code,name'])
            ->orderBy('part_number')
            ->get(['id', 'part_number', 'part_name', 'model', 'size', 'uom_id', 'part_type_id'])
            ->map(fn (Part $part) => [
                'part_id' => (int) $part->id,
                'part_number' => $part->part_number,
                'part_name' => $part->part_name,
                'model' => $part->model,
                'size' => trim((string) ($part->size ?: 'STD')),
                'unit' => $part->uom?->code,
                'part_type' => $part->partType?->code,
            ])
            ->values()
            ->all();
    }

    /**
     * Validasi item kedatangan OSP terhadap daftar part BOM yang sesuai sub-jenisnya.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function resolveOspItems(array $items, string $arrivalType): array
    {
        $allowedLookup = array_flip($this->ospPartIds($arrivalType));
        $errorMessage = $arrivalType === IncomingArrival::TYPE_OSP_GCI
            ? __('Part tidak terdaftar sebagai proses Subcon di BOM aktif.')
            : __('Part tidak terdaftar sebagai FREE_ISSUE / OSP di BOM aktif.');

        $partIds = collect($items)
            ->pluck('part_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $partsById = $partIds === []
            ? collect()
            : Part::query()
                ->with('uom:id,code')
                ->whereIn('id', $partIds)
                ->where('is_active', true)
                ->get()
                ->keyBy('id');

        return collect($items)->map(function (array $item, int $index) use ($allowedLookup, $partsById, $errorMessage) {
            $partId = ! empty($item['part_id']) ? (int) $item['part_id'] : null;
            $part = $partId !== null ? $partsById->get($partId) : null;

            if ($partId === null || ! isset($allowedLookup[$partId]) || ! $part) {
                throw ValidationException::withMessages([
                    "items.{$index}.part_id" => $errorMessage,
                ]);
            }

            $size = trim((string) ($item['size'] ?? ''));
            $unitGoods = ! empty($item['unit_goods'])
                ? (UomCatalog::normalize((string) $item['unit_goods']) ?? (string) $item['unit_goods'])
                : ($part->uom?->code ?? UomCatalog::PIECE);

            $item['part_id'] = (int) $part->id;
            $item['material_group'] = null;
            $item['size'] = $size !== '' ? $size : ($part->size ?: 'STD');
            $item['unit_goods'] = $unitGoods;

            return $item;
        })->all();
    }

    private function resolveArrivalType(Request $request, ?IncomingArrival $existing = null): string
    {
        $requestedType = $request->input('arrival_type');
        if (is_string($requestedType) && $requestedType !== '') {
            return $requestedType;
        }

        if ($existing !== null && ! $request->has('is_local')) {
            return $existing->resolvedArrivalType();
        }

        $isLocal = filter_var($request->input('is_local', $existing?->is_local ?? false), FILTER_VALIDATE_BOOLEAN);

        return $isLocal ? IncomingArrival::TYPE_LOCAL : IncomingArrival::TYPE_IMPORT;
    }

    /**
     * Aturan validasi bersama untuk store & update kedatangan.
     *
     * @return array<string, mixed>
     */
    private function arrivalRules(string $arrivalType, bool $isUpdate = false): array
    {
        $rules = [
            'is_local' => ['nullable', 'boolean'],
            'arrival_type' => ['nullable', Rule::in(IncomingArrival::TYPES)],
            'invoice_no' => ['nullable', 'string', 'max:255'],
            'delivery_note_no' => ['nullable', 'string', 'max:255'],
            'contract_no' => ['nullable', 'string', 'max:100'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'invoice_date' => ['nullable', 'date'],
            'pen_no' => ['nullable', 'string', 'max:255'],
            'pen_date' => ['nullable', 'date'],
            'aju_no' => ['nullable', 'string', 'max:255'],
            'aju_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
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

        if ($isUpdate) {
            $rules['status'] = ['required', 'in:pending,completed,cancelled'];
            $rules['items.*.id'] = ['nullable', 'integer', 'exists:incoming_arrival_items,id'];
            $rules['items.*.is_foc'] = ['nullable', 'boolean'];
        }

        if ($arrivalType === IncomingArrival::TYPE_OSP_GCI) {
            $rules['subcontractor_id'] = ['required', Rule::exists('subcontractors', 'id')->where('is_active', true)];
        } elseif ($arrivalType === IncomingArrival::TYPE_OSP_CUSTOMER) {
            $rules['customer_id'] = ['required', Rule::exists('customers', 'id')->where('is_active', true)];
        } else {
            $rules['supplier_id'] = ['required', Rule::exists('suppliers', 'id')->where('is_active', true)];
            $rules['purchase_order_id'] = ['nullable', 'exists:purchase_orders,id'];
        }

        if ($arrivalType === IncomingArrival::TYPE_IMPORT) {
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
                'containers' => ['nullable', 'array'],
                'containers.*.container_no' => ['required_with:containers', 'string', 'max:255'],
                'containers.*.seal_code' => ['nullable', 'string', 'max:255'],
                'containers.*.size' => ['nullable', 'string', 'max:50'],
            ];
        }

        return $rules;
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', IncomingArrival::class);

        $arrivalType = (string) $request->input('arrival_type', '');
        $arrivalType = in_array($arrivalType, IncomingArrival::TYPES, true) ? $arrivalType : '';

        $arrivals = IncomingArrival::query()
            ->with([
                'supplier:id,supplier_code,supplier_name',
                'subcontractor:id,subcontractor_code,subcontractor_name',
                'customer:id,customer_code,customer_name',
                'items.receives',
            ])
            ->withCount('items')
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('arrival_no', 'ilike', "%{$s}%")
                ->orWhere('invoice_no', 'ilike', "%{$s}%")
                ->orWhere('delivery_note_no', 'ilike', "%{$s}%")
                ->orWhere('contract_no', 'ilike', "%{$s}%")
                ->orWhere('reference_no', 'ilike', "%{$s}%")
                ->orWhere('transaction_no', 'ilike', "%{$s}%")
                ->orWhereHas('supplier', fn ($sq) => $sq->where('supplier_name', 'ilike', "%{$s}%"))
                ->orWhereHas('subcontractor', fn ($sq) => $sq->where('subcontractor_name', 'ilike', "%{$s}%"))
                ->orWhereHas('customer', fn ($sq) => $sq->where('customer_name', 'ilike', "%{$s}%"))))
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($arrivalType !== '', fn ($q) => $q->where('arrival_type', $arrivalType))
            ->orderByDesc('created_at')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Incoming/Arrival/Index', [
            'arrivals' => $arrivals,
            'filters' => [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
                'arrival_type' => $arrivalType !== '' ? $arrivalType : null,
            ],
        ]);
    }

    /**
     * Syarat harga (FOB, CIF, dan sejenisnya) adalah kode huruf besar. Nilai
     * disimpan dalam bentuk yang sama dengan yang tercetak di invoice, supaya
     * form, halaman detail, dan cetakan tidak menampilkan tiga ejaan berbeda.
     */
    private function normalizePriceTerm(?string $value): ?string
    {
        $normalized = strtoupper(trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Mengambil daftar PO aktif yang masih memiliki minimal 1 item bersisa belum tiba,
     * atau menyertakan PO saat ini jika dalam mode edit.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function activePurchaseOrders(?int $currentPoId = null, ?int $excludeArrivalId = null)
    {
        $pos = PurchaseOrder::query()
            ->with(['items.part.uom', 'supplier:id,source,supplier_name'])
            ->whereNotIn('status', ['cancelled'])
            ->select('id', 'po_no', 'supplier_id', 'status', 'currency', 'po_date', 'expected_date')
            ->orderByDesc('created_at')
            ->get();

        $poIds = $pos->pluck('id')->all();
        $arrivalItemsByPoPart = $poIds === []
            ? collect()
            : IncomingArrivalItem::query()
                ->select(
                    'incoming_arrival_items.id',
                    'incoming_arrival_items.arrival_id',
                    'incoming_arrival_items.part_id',
                    'incoming_arrival_items.qty_goods',
                    'incoming_arrival_items.unit_goods',
                    'incoming_arrival_items.weight_nett',
                    'incoming_arrival_items.unit_weight',
                    'ia.purchase_order_id',
                )
                ->join('incoming_arrivals as ia', 'ia.id', '=', 'incoming_arrival_items.arrival_id')
                ->whereNull('ia.deleted_at')
                ->whereIn('ia.purchase_order_id', $poIds)
                ->when($excludeArrivalId !== null, fn ($q) => $q->where('ia.id', '!=', $excludeArrivalId))
                ->get()
                ->groupBy(fn ($row) => $row->purchase_order_id.'#'.$row->part_id);

        return $pos->map(function (PurchaseOrder $po) use ($arrivalItemsByPoPart) {
            $mappedItems = $po->items->map(function (PurchaseOrderItem $it) use ($po, $arrivalItemsByPoPart): array {
                $poUnit = UomCatalog::normalize($it->unit)
                    ?? UomCatalog::normalize($it->part?->uom?->code)
                    ?? UomCatalog::WEIGHT;
                $poQty = (float) $it->qty;

                $arrived = 0.0;
                foreach ($arrivalItemsByPoPart->get($po->id.'#'.$it->part_id, collect()) as $row) {
                    $contribution = PurchaseOrderLimit::itemContributionForPoUnit(
                        (float) $row->qty_goods,
                        $row->unit_goods,
                        $row->weight_nett !== null ? (float) $row->weight_nett : null,
                        $row->unit_weight,
                        $poUnit,
                    );
                    if ($contribution !== null) {
                        $arrived += $contribution;
                    }
                }

                $arrivedRounded = round($arrived, 4);
                $remainingRounded = max(0.0, round($poQty - $arrived, 4));

                return [
                    'id' => $it->id,
                    'part_id' => $it->part_id,
                    'size' => $it->size,
                    'qty' => $poQty,
                    'arrived_qty' => $arrivedRounded,
                    'remaining_qty' => $remainingRounded,
                    'unit' => $poUnit,
                    'price' => (float) $it->price,
                    'notes' => $it->notes,
                    'part' => $it->part ? [
                        'id' => $it->part->id,
                        'part_number' => $it->part->part_number,
                        'part_name' => $it->part->part_name,
                        'model' => $it->part->model,
                        'size' => $it->part->size,
                    ] : null,
                ];
            })->values();

            $totalPoQty = round((float) $mappedItems->sum('qty'), 4);
            $arrivedQty = round((float) $mappedItems->sum('arrived_qty'), 4);
            $remainingQty = round((float) $mappedItems->sum('remaining_qty'), 4);

            return [
                'id' => $po->id,
                'po_no' => $po->po_no,
                'supplier_id' => $po->supplier_id,
                'status' => $po->status,
                'currency' => $po->currency,
                'total_qty' => $totalPoQty,
                'arrived_qty' => $arrivedQty,
                'remaining_qty' => $remainingQty,
                'has_remaining_item' => $mappedItems->contains(fn (array $it) => $it['remaining_qty'] > 1e-6),
                'supplier' => $po->supplier ? [
                    'id' => $po->supplier->id,
                    'supplier_name' => $po->supplier->supplier_name,
                    'source' => $po->supplier->source,
                ] : null,
                'items' => $mappedItems->all(),
            ];
        })->filter(function (array $poData) use ($currentPoId) {
            if ($currentPoId !== null && (int) $poData['id'] === (int) $currentPoId) {
                return true;
            }

            if ($poData['status'] === 'completed') {
                return false;
            }

            // Jika PO belum memiliki item (legacy), tetap tampilkan agar bisa diisi.
            return $poData['items'] === [] || $poData['has_remaining_item'];
        })->values();
    }

    /**
     * Properti Inertia bersama untuk halaman create & edit kedatangan.
     *
     * @return array<string, mixed>
     */
    private function formProps(?IncomingArrival $arrival = null): array
    {
        return [
            'arrival' => $arrival,
            'suppliers' => Supplier::select('id', 'supplier_code', 'supplier_name', 'source')
                ->where('is_active', true)
                ->orderBy('supplier_name')
                ->get(),
            'subcontractors' => Subcontractor::select('id', 'subcontractor_code', 'subcontractor_name')
                ->where('is_active', true)
                ->orderBy('subcontractor_name')
                ->get(),
            'customers' => Customer::select('id', 'customer_code', 'customer_name', 'short_name')
                ->where('is_active', true)
                ->orderBy('customer_name')
                ->get(),
            'truckings' => TruckingCompany::select('id', 'company_code', 'company_name')
                ->where('is_active', true)
                ->orderBy('company_name')
                ->get(),
            'materialOptions' => $this->materialOptions(),
            'ospGciParts' => $this->ospPartOptions(IncomingArrival::TYPE_OSP_GCI),
            'ospCustomerParts' => $this->ospPartOptions(IncomingArrival::TYPE_OSP_CUSTOMER),
            'purchaseOrders' => $this->activePurchaseOrders($arrival?->purchase_order_id, $arrival?->id),
            'suppliersWithPos' => PurchaseOrder::query()
                ->whereNotIn('status', ['cancelled'])
                ->whereNotNull('supplier_id')
                ->distinct()
                ->pluck('supplier_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
            'uomCodes' => UomCatalog::codes(),
            'packingUnits' => UomCatalog::packingUnits(),
        ];
    }

    public function create(): Response
    {
        Gate::authorize('create', IncomingArrival::class);

        return Inertia::render('Incoming/Arrival/Form', $this->formProps());
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', IncomingArrival::class);

        $arrivalType = $this->resolveArrivalType($request);
        $isOsp = in_array($arrivalType, IncomingArrival::OSP_TYPES, true);
        $isLocal = $arrivalType === IncomingArrival::TYPE_LOCAL;
        $isImport = $arrivalType === IncomingArrival::TYPE_IMPORT;

        $validated = $request->validate($this->arrivalRules($arrivalType));

        if ($isOsp) {
            $validated['items'] = $this->resolveOspItems($validated['items'], $arrivalType);
        } else {
            $validated['items'] = $this->resolveMaterialItems($validated['items'], (int) $validated['supplier_id']);
            $this->assertWithinPurchaseOrderLimit(
                isset($validated['purchase_order_id']) ? (int) $validated['purchase_order_id'] : null,
                $validated['items'],
            );
        }

        $createArrival = function () use ($validated, $arrivalType, $isOsp, $isLocal, $isImport, $request) {
            $arrival = IncomingArrival::create([
                'arrival_no' => IncomingArrival::generateArrivalNo($isOsp ? 'OSP' : 'ARV'),
                'is_local' => $isLocal,
                'arrival_type' => $arrivalType,
                'invoice_no' => $validated['invoice_no'] ?? null,
                'delivery_note_no' => $validated['delivery_note_no'] ?? null,
                'contract_no' => $validated['contract_no'] ?? null,
                'reference_no' => $validated['reference_no'] ?? null,
                'invoice_date' => $validated['invoice_date'] ?? null,
                'supplier_id' => $isOsp ? null : ($validated['supplier_id'] ?? null),
                'subcontractor_id' => $arrivalType === IncomingArrival::TYPE_OSP_GCI ? ($validated['subcontractor_id'] ?? null) : null,
                'customer_id' => $arrivalType === IncomingArrival::TYPE_OSP_CUSTOMER ? ($validated['customer_id'] ?? null) : null,
                'trucking_company_id' => $isImport ? ($validated['trucking_company_id'] ?? null) : null,
                'purchase_order_id' => $isOsp ? null : ($validated['purchase_order_id'] ?? null),
                'vessel' => $isImport ? ($validated['vessel'] ?? null) : null,
                'etd' => $isImport ? ($validated['etd'] ?? null) : null,
                'eta' => $isImport ? ($validated['eta'] ?? null) : null,
                'eta_gci' => $isImport ? ($validated['eta_gci'] ?? null) : null,
                'bill_of_lading' => $isImport ? ($validated['bill_of_lading'] ?? null) : null,
                'pen_no' => $validated['pen_no'] ?? null,
                'pen_date' => $validated['pen_date'] ?? null,
                'aju_no' => $validated['aju_no'] ?? null,
                'aju_date' => $validated['aju_date'] ?? null,
                'price_term' => $isImport ? $this->normalizePriceTerm($validated['price_term'] ?? null) : null,
                'hs_code' => $isImport ? ($validated['hs_code'] ?? null) : null,
                'port_of_loading' => $isImport ? ($validated['port_of_loading'] ?? null) : null,
                'country' => $isImport ? ($validated['country'] ?? null) : null,
                'currency' => $validated['currency'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
                'status' => 'pending',
                'iqc_status' => 'pending',
                'created_by' => $request->user()?->id,
            ]);

            if ($isImport) {
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
                    'total_price' => isset($item['total_price']) && $item['total_price'] !== '' ? round((float) $item['total_price'], 2) : null,
                    'is_foc' => (bool) ($item['is_foc'] ?? false),
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            return $arrival;
        };

        $arrival = DocumentNumber::retry(
            fn () => DB::transaction($createArrival),
            'incoming_arrivals_arrival_no_unique',
        );

        return redirect()->route('incoming-arrivals.show', $arrival)->with('success', __('Arrival created.'));
    }

    /**
     * Pastikan total kedatangan tidak melebihi qty baris PO (akumulatif lintas arrival dan baris ganda).
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function assertWithinPurchaseOrderLimit(?int $purchaseOrderId, array $items, ?int $excludeArrivalId = null): void
    {
        if ($purchaseOrderId === null) {
            return;
        }

        $poItems = PurchaseOrderItem::query()
            ->with('part.uom')
            ->where('purchase_order_id', $purchaseOrderId)
            ->get()
            ->keyBy('part_id');

        if ($poItems->isEmpty()) {
            return;
        }

        $errors = [];
        $cumulativeByPart = [];

        foreach ($items as $index => $item) {
            $partId = isset($item['part_id']) ? (int) $item['part_id'] : null;
            if ($partId === null || ! $poItems->has($partId)) {
                $errors["items.$index.part_id"] = __('Part tidak terdaftar pada Purchase Order yang dipilih.');

                continue;
            }

            $poItem = $poItems->get($partId);
            $poUnit = UomCatalog::normalize($poItem->unit)
                ?? UomCatalog::normalize($poItem->part?->uom?->code)
                ?? UomCatalog::WEIGHT;

            $contribution = PurchaseOrderLimit::itemContributionForPoUnit(
                (float) ($item['qty_goods'] ?? 0),
                $item['unit_goods'] ?? null,
                isset($item['weight_nett']) && $item['weight_nett'] !== '' ? (float) $item['weight_nett'] : null,
                $item['unit_weight'] ?? null,
                $poUnit,
            );

            if ($contribution === null) {
                $errors["items.$index.unit_goods"] = __('Satuan kedatangan tidak sesuai dengan satuan PO (:unit).', ['unit' => $poUnit]);

                continue;
            }

            if ($contribution <= 0) {
                $errors["items.$index.qty_goods"] = __('Qty kedatangan harus lebih dari 0.');

                continue;
            }

            $cumulativeByPart[$partId] = ($cumulativeByPart[$partId] ?? 0.0) + $contribution;
            $limit = PurchaseOrderLimit::arrivalLimit($purchaseOrderId, $partId, $poUnit, null, $excludeArrivalId);

            if ($limit !== null && $cumulativeByPart[$partId] > $limit['remaining'] + 1e-9) {
                $errors["items.$index.qty_goods"] = __('Qty kedatangan (:qty :unit) melebihi qty PO (:po :unit). Sisa yang bisa dikedatangkan :remaining :unit.', [
                    'qty' => round($cumulativeByPart[$partId], 4),
                    'unit' => $poUnit,
                    'po' => $limit['po_qty'],
                    'remaining' => round($limit['remaining'], 4),
                ]);
            }
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
            'subcontractor',
            'customer',
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
            'can' => [
                'inspect' => Gate::allows('update', $arrival),
            ],
        ]);
    }

    public function edit(IncomingArrival $arrival): Response
    {
        Gate::authorize('update', $arrival);

        $arrival->load(['supplier', 'subcontractor', 'customer', 'items.part.uom', 'containers']);

        return Inertia::render('Incoming/Arrival/Form', $this->formProps($arrival));
    }

    public function update(Request $request, IncomingArrival $arrival): RedirectResponse
    {
        Gate::authorize('update', $arrival);

        $arrivalType = $this->resolveArrivalType($request, $arrival);
        $isOsp = in_array($arrivalType, IncomingArrival::OSP_TYPES, true);
        $isLocal = $arrivalType === IncomingArrival::TYPE_LOCAL;
        $isImport = $arrivalType === IncomingArrival::TYPE_IMPORT;

        $validated = $request->validate($this->arrivalRules($arrivalType, isUpdate: true));

        if ($isOsp) {
            $validated['items'] = $this->resolveOspItems($validated['items'], $arrivalType);
        } else {
            $validated['items'] = $this->resolveMaterialItems($validated['items'], (int) $validated['supplier_id']);
            $this->assertWithinPurchaseOrderLimit(
                isset($validated['purchase_order_id']) ? (int) $validated['purchase_order_id'] : null,
                $validated['items'],
                $arrival->id,
            );
        }
        $validated['updated_by'] = $request->user()?->id;

        DB::transaction(function () use ($arrival, $validated, $arrivalType, $isOsp, $isLocal, $isImport) {
            $updateFields = [
                'is_local' => $isLocal,
                'arrival_type' => $arrivalType,
                'invoice_no' => $validated['invoice_no'] ?? null,
                'delivery_note_no' => $validated['delivery_note_no'] ?? null,
                'contract_no' => $validated['contract_no'] ?? null,
                'reference_no' => $validated['reference_no'] ?? null,
                'invoice_date' => $validated['invoice_date'] ?? null,
                'supplier_id' => $isOsp ? null : ($validated['supplier_id'] ?? null),
                'subcontractor_id' => $arrivalType === IncomingArrival::TYPE_OSP_GCI ? ($validated['subcontractor_id'] ?? null) : null,
                'customer_id' => $arrivalType === IncomingArrival::TYPE_OSP_CUSTOMER ? ($validated['customer_id'] ?? null) : null,
                'purchase_order_id' => $isOsp ? null : ($validated['purchase_order_id'] ?? null),
                'pen_no' => $validated['pen_no'] ?? null,
                'pen_date' => $validated['pen_date'] ?? null,
                'aju_no' => $validated['aju_no'] ?? null,
                'aju_date' => $validated['aju_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
                'status' => $validated['status'],
                'updated_by' => $validated['updated_by'],
            ];

            if (array_key_exists('currency', $validated)) {
                $updateFields['currency'] = $validated['currency'];
            }

            if ($isImport) {
                $updateFields += [
                    'trucking_company_id' => $validated['trucking_company_id'] ?? null,
                    'vessel' => $validated['vessel'] ?? null,
                    'etd' => $validated['etd'] ?? null,
                    'eta' => $validated['eta'] ?? null,
                    'eta_gci' => $validated['eta_gci'] ?? null,
                    'bill_of_lading' => $validated['bill_of_lading'] ?? null,
                    'price_term' => $this->normalizePriceTerm($validated['price_term'] ?? null),
                    'hs_code' => $validated['hs_code'] ?? null,
                    'port_of_loading' => $validated['port_of_loading'] ?? null,
                    'country' => $validated['country'] ?? null,
                ];
            }

            $arrival->update($updateFields);

            $submittedIds = [];
            foreach ($validated['items'] as $item) {
                $payload = \Arr::except($item, ['id']);
                $payload['arrival_id'] = $arrival->id;
                if (array_key_exists('total_price', $payload)) {
                    $payload['total_price'] = $payload['total_price'] !== null && $payload['total_price'] !== ''
                        ? round((float) $payload['total_price'], 2)
                        : null;
                }
                $savedItem = $arrival->items()->updateOrCreate(
                    ['id' => $item['id'] ?? null],
                    $payload,
                );
                $submittedIds[] = $savedItem->id;
            }

            $arrival->items()
                ->whereNotIn('id', $submittedIds)
                ->whereDoesntHave('receives')
                ->delete();

            // Container hanya untuk kedatangan impor: sinkronisasi agar nomor yang
            // dihapus benar-benar hilang tanpa melanggar unique constraint jika
            // nomor kontainer yang sama tetap dipertahankan.
            if (! $isImport) {
                $arrival->containers()->delete();
            } else {
                $currentContainerNos = collect($validated['containers'] ?? [])
                    ->pluck('container_no')
                    ->filter()
                    ->all();

                $arrival->containers()
                    ->whereNotIn('container_no', $currentContainerNos)
                    ->delete();

                foreach ($validated['containers'] ?? [] as $container) {
                    $existing = IncomingArrivalContainer::withTrashed()
                        ->where('arrival_id', $arrival->id)
                        ->where('container_no', $container['container_no'])
                        ->first();

                    if ($existing) {
                        if ($existing->trashed()) {
                            $existing->restore();
                        }
                        $existing->update([
                            'seal_code' => $container['seal_code'] ?? null,
                            'size' => $container['size'] ?? null,
                        ]);
                    } else {
                        IncomingArrivalContainer::create([
                            'arrival_id' => $arrival->id,
                            'container_no' => $container['container_no'],
                            'seal_code' => $container['seal_code'] ?? null,
                            'size' => $container['size'] ?? null,
                        ]);
                    }
                }
            }
        });

        return redirect()->route('incoming-arrivals.show', $arrival)->with('success', __('Arrival updated.'));
    }

    public function destroy(IncomingArrival $arrival): RedirectResponse
    {
        Gate::authorize('delete', $arrival);

        if ($arrival->items()->whereHas('receives')->exists()) {
            return back()->with('error', __('Kedatangan tidak dapat dihapus karena sudah memiliki penerimaan. Hapus penerimaan terlebih dahulu.'));
        }

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

        $arrival->load(['supplier', 'subcontractor', 'customer', 'trucking', 'purchaseOrder', 'items.part', 'containers']);

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

        $arrival->load(['supplier', 'subcontractor', 'customer', 'trucking', 'purchaseOrder', 'items.part', 'containers']);

        $filename = 'Commercial-Invoice-'.str_replace(['/', '\\'], '-', ($arrival->invoice_no ?: $arrival->arrival_no)).'.pdf';

        $pdf = Pdf::loadView('arrivals.invoice', [
            'arrival' => $arrival,
            'browserPrint' => false,
        ])->setPaper('a4', 'portrait');

        return $pdf->download($filename);
    }
}
