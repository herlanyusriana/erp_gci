<?php

namespace App\Http\Controllers;

use App\Exports\ArrivalDetailExport;
use App\Exports\LocalPoExport;
use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class LocalPoController extends Controller
{
    private function findLocalOrFail(IncomingArrival $arrival): IncomingArrival
    {
        abort_unless((bool) $arrival->is_local, 404);

        return $arrival;
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', IncomingArrival::class);

        $q = strtoupper(trim((string) $request->query('search', '')));
        $supplierId = $request->query('supplier_id');

        $localPos = IncomingArrival::query()
            ->where('is_local', true)
            ->with(['supplier:id,supplier_code,supplier_name', 'items.receives'])
            ->when($supplierId, fn ($qa) => $qa->where('supplier_id', $supplierId))
            ->when($q !== '', fn ($qa) => $qa->where(function ($inner) use ($q) {
                $inner->where('invoice_no', 'ilike', "%{$q}%")
                    ->orWhere('arrival_no', 'ilike', "%{$q}%");
            }))
            ->orderByDesc('created_at')
            ->paginate(10)
            ->withQueryString();

        $localPos->getCollection()->transform(function (IncomingArrival $arrival) {
            $arrival->items_count = $arrival->items->count();
            $arrival->remaining_qty = $arrival->items->sum(function ($item) {
                $received = $item->receives->sum('qty');

                return max(0, (float) $item->qty_goods - (float) $received);
            });

            return $arrival;
        });

        return Inertia::render('Incoming/LocalPo/Index', [
            'localPos' => $localPos,
            'suppliers' => \App\Models\Supplier::select('id', 'supplier_code', 'supplier_name')
                ->orderBy('supplier_name')->get(),
            'filters' => $request->only('search', 'supplier_id'),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', IncomingArrival::class);

        return Inertia::render('Incoming/LocalPo/Form', [
            'arrival' => null,
            'suppliers' => \App\Models\Supplier::select('id', 'supplier_code', 'supplier_name')
                ->where('is_active', true)->orderBy('supplier_name')->get(),
            'parts' => \App\Models\Part::select('id', 'part_number', 'part_name')
                ->where('is_active', true)
                ->whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))
                ->orderBy('part_number')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', IncomingArrival::class);

        $request->merge(['po_no' => strtoupper(trim((string) $request->input('po_no', '')))]);

        $validated = $request->validate([
            'po_no' => ['required', 'string', 'max:255', Rule::unique('incoming_arrivals', 'invoice_no')],
            'po_date' => ['required', 'date'],
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'currency' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.part_id' => ['required', 'exists:parts,id', \App\Rules\PartTypeRule::notFg()],
            'items.*.size' => ['nullable', 'string', 'max:100'],
            'items.*.qty_goods' => ['required', 'numeric', 'min:0'],
            'items.*.unit_goods' => ['required', 'in:PCS,COIL,SHEET,SET,EA,KGM,ROLL,UOM'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        $currency = strtoupper(trim((string) ($validated['currency'] ?? 'IDR')));
        if ($currency === '') {
            $currency = 'IDR';
        }

        $arrival = DB::transaction(function () use ($validated, $request, $currency) {
            $arrival = IncomingArrival::create([
                'arrival_no' => IncomingArrival::generateArrivalNo('LPO'),
                'invoice_no' => $validated['po_no'],
                'invoice_date' => $validated['po_date'],
                'supplier_id' => $validated['supplier_id'],
                'currency' => $currency,
                'notes' => $validated['notes'] ?? null,
                'status' => 'pending',
                'is_local' => true,
                'created_by' => $request->user()?->id,
            ]);

            foreach ($validated['items'] as $item) {
                $qtyGoods = (float) $item['qty_goods'];
                $price = (float) ($item['price'] ?? 0);

                $arrival->items()->create([
                    'part_id' => (int) $item['part_id'],
                    'size' => isset($item['size']) && trim((string) $item['size']) !== ''
                        ? strtoupper(trim((string) $item['size'])) : null,
                    'qty_goods' => $qtyGoods,
                    'unit_goods' => strtoupper((string) $item['unit_goods']),
                    'qty_bundle' => 0,
                    'unit_bundle' => 'PALLET',
                    'weight_nett' => 0,
                    'unit_weight' => 'KGM',
                    'weight_gross' => 0,
                    'price' => $price,
                    'total_price' => $qtyGoods * $price,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            return $arrival;
        });

        return redirect()->route('local-pos.show', $arrival)->with('success', __('Local PO created. Silakan lakukan receive.'));
    }

    public function show(IncomingArrival $localPo): Response
    {
        $arrival = $this->findLocalOrFail($localPo);
        Gate::authorize('view', $arrival);

        $arrival->load(['supplier', 'items.part', 'items.receives']);

        $pending = collect($arrival->items)->map(function ($item) {
            $received = $item->receives->sum('qty');

            return [
                'item' => $item,
                'received' => (float) $received,
                'remaining' => max(0, (float) $item->qty_goods - (float) $received),
            ];
        });

        return Inertia::render('Incoming/LocalPo/Show', [
            'arrival' => $arrival,
            'pending' => $pending,
        ]);
    }

    public function edit(IncomingArrival $localPo): Response
    {
        $arrival = $this->findLocalOrFail($localPo);
        Gate::authorize('update', $arrival);

        $arrival->load(['supplier', 'items.part']);

        return Inertia::render('Incoming/LocalPo/Form', [
            'arrival' => $arrival,
            'suppliers' => \App\Models\Supplier::select('id', 'supplier_code', 'supplier_name')
                ->where('is_active', true)->orderBy('supplier_name')->get(),
            'parts' => \App\Models\Part::select('id', 'part_number', 'part_name')
                ->where('is_active', true)
                ->whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))
                ->orderBy('part_number')->get(),
        ]);
    }

    public function update(Request $request, IncomingArrival $localPo): RedirectResponse
    {
        $arrival = $this->findLocalOrFail($localPo);
        Gate::authorize('update', $arrival);

        $request->merge(['po_no' => strtoupper(trim((string) $request->input('po_no', '')))]);

        $validated = $request->validate([
            'po_no' => ['required', 'string', 'max:255', Rule::unique('incoming_arrivals', 'invoice_no')->ignore($arrival->id)],
            'po_date' => ['required', 'date'],
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'currency' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.part_id' => ['required', 'exists:parts,id', \App\Rules\PartTypeRule::notFg()],
            'items.*.size' => ['nullable', 'string', 'max:100'],
            'items.*.qty_goods' => ['required', 'numeric', 'min:0'],
            'items.*.unit_goods' => ['required', 'in:PCS,COIL,SHEET,SET,EA,KGM,ROLL,UOM'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        $blocked = DB::transaction(function () use ($arrival, $validated) {
            $arrival->update([
                'invoice_no' => $validated['po_no'],
                'invoice_date' => $validated['po_date'],
                'supplier_id' => $validated['supplier_id'],
                'currency' => strtoupper($validated['currency'] ?? 'IDR'),
                'notes' => $validated['notes'] ?? null,
            ]);

            $inputIds = collect($validated['items'])
                ->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();

            $toDelete = $arrival->items()->whereNotIn('id', $inputIds)->get();
            foreach ($toDelete as $doomed) {
                if ($doomed->receives()->exists()) {
                    return __('Item :number sudah ada receive, tidak bisa dihapus via Edit.', ['number' => $doomed->part?->part_number]);
                }
                $doomed->delete();
            }

            foreach ($validated['items'] as $itemData) {
                $qtyGoods = (float) $itemData['qty_goods'];
                $price = (float) ($itemData['price'] ?? 0);

                $data = [
                    'part_id' => (int) $itemData['part_id'],
                    'size' => isset($itemData['size']) && trim((string) $itemData['size']) !== ''
                        ? strtoupper(trim((string) $itemData['size'])) : null,
                    'qty_goods' => $qtyGoods,
                    'unit_goods' => strtoupper((string) $itemData['unit_goods']),
                    'price' => $price,
                    'total_price' => $qtyGoods * $price,
                    'notes' => $itemData['notes'] ?? null,
                ];

                if (!empty($itemData['id'])) {
                    $itemModel = $arrival->items()->findOrFail((int) $itemData['id']);
                    if ($itemModel->receives()->exists() && (int) $itemModel->part_id !== (int) $data['part_id']) {
                        return __('Item :number sudah ada receive, part tidak boleh diganti.', ['number' => $itemModel->part?->part_number]);
                    }
                    $itemModel->update($data);
                } else {
                    $arrival->items()->create($data);
                }
            }

            return null;
        });

        if ($blocked !== null) {
            return back()->withInput()->withErrors(['items' => $blocked]);
        }

        return redirect()->route('local-pos.index')->with('success', __('Local PO updated.'));
    }

    public function destroy(IncomingArrival $localPo): RedirectResponse
    {
        $arrival = $this->findLocalOrFail($localPo);
        Gate::authorize('delete', $arrival);

        DB::transaction(function () use ($arrival) {
            $arrival->items()->delete();
            $arrival->delete();
        });

        return redirect()->route('local-pos.index')->with('success', __('Local PO deleted.'));
    }

    public function export()
    {
        Gate::authorize('viewAny', IncomingArrival::class);

        return Excel::download(new LocalPoExport(), 'local_po_' . date('Y-m-d_His') . '.xlsx');
    }

    public function exportDetail(IncomingArrival $localPo)
    {
        $arrival = $this->findLocalOrFail($localPo);
        Gate::authorize('view', $arrival);

        $poNo = preg_replace('/[^A-Za-z0-9_-]/', '_', $arrival->invoice_no ?? 'PO');

        return Excel::download(new ArrivalDetailExport($arrival), 'local_po_' . $poNo . '_' . date('Y-m-d') . '.xlsx');
    }
}
