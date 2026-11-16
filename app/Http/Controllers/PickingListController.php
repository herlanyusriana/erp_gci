<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Services\PickingListService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PickingListController extends Controller
{
    public function __construct(private PickingListService $pickingService) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PickingList::class);

        $lists = PickingList::query()
            ->with('creator:id,name')
            ->withCount(['items', 'deliveries'])
            ->when($request->input('search'), fn ($q, $s) => $q->where('picking_no', 'ilike', "%{$s}%"))
            ->when($request->input('from'), fn ($q, $d) => $q->whereDate('picking_date', '>=', $d))
            ->when($request->input('to'), fn ($q, $d) => $q->whereDate('picking_date', '<=', $d))
            ->orderByDesc('picking_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Outgoing/Picking/Index', [
            'lists' => $lists,
            'filters' => $request->only('search', 'from', 'to'),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', PickingList::class);

        return Inertia::render('Outgoing/Picking/Form', [
            'pickingList' => null,
            'customers' => $this->activeCustomers(),
            'parts' => $this->pickingService->selectableParts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', PickingList::class);

        $validated = $this->validatePayload($request);

        $list = $this->pickingService->create($validated, $request->user()?->id);

        return redirect()
            ->route('picking-lists.show', $list)
            ->with('success', __('Picking list :no dibuat.', ['no' => $list->picking_no]));
    }

    public function show(PickingList $pickingList): Response
    {
        Gate::authorize('view', $pickingList);

        $pickingList->load([
            'items.customer:id,customer_code,customer_name',
            'items.part:id,part_number,part_name,model,uom_id',
            'items.part.uom:id,code',
            'deliveries:id,delivery_no,customer_id,status,picking_list_id',
            'deliveries.customer:id,customer_code,customer_name',
            'creator:id,name',
        ]);

        return Inertia::render('Outgoing/Picking/Show', [
            'pickingList' => $pickingList,
            'stockStatuses' => [
                PickingListItem::STOCK_OK,
                PickingListItem::STOCK_SHORT_IN_PRODUCTION,
                PickingListItem::STOCK_SHORT_NO_PRODUCTION,
                PickingListItem::STOCK_NO_DATA,
            ],
        ]);
    }

    public function edit(PickingList $pickingList): Response
    {
        Gate::authorize('update', $pickingList);

        $pickingList->load('items');

        return Inertia::render('Outgoing/Picking/Form', [
            'pickingList' => $pickingList,
            'customers' => $this->activeCustomers(),
            'parts' => $this->pickingService->selectableParts(),
        ]);
    }

    public function update(Request $request, PickingList $pickingList): RedirectResponse
    {
        Gate::authorize('update', $pickingList);

        $validated = $this->validatePayload($request);

        $this->pickingService->update($pickingList, $validated, $request->user()?->id);

        return redirect()
            ->route('picking-lists.show', $pickingList)
            ->with('success', __('Picking list :no diperbarui.', ['no' => $pickingList->picking_no]));
    }

    public function destroy(PickingList $pickingList): RedirectResponse
    {
        Gate::authorize('delete', $pickingList);

        if ($pickingList->deliveries()->exists()) {
            return back()->with('error', __('Picking list yang sudah menghasilkan pengiriman tidak dapat dihapus.'));
        }

        $pickingList->delete();

        return redirect()
            ->route('picking-lists.index')
            ->with('success', __('Picking list dihapus.'));
    }

    /** Buat satu Delivery draft per customer dari baris picking. */
    public function generateDeliveries(Request $request, PickingList $pickingList): RedirectResponse
    {
        Gate::authorize('update', $pickingList);

        $deliveries = $this->pickingService->generateDeliveries($pickingList, $request->user()?->id);

        return redirect()
            ->route('picking-lists.show', $pickingList)
            ->with('success', __(':count pengiriman dibuat dari picking list ini.', ['count' => $deliveries->count()]));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'picking_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sequence' => ['required', 'integer', 'min:1'],
            'items.*.customer_id' => ['required', 'integer', 'exists:customers,id'],
            'items.*.part_id' => [
                'required', 'integer',
                Rule::exists('parts', 'id')->where(fn ($q) => $q
                    ->whereNull('deleted_at')
                    ->whereIn('part_type_id', fn ($sub) => $sub
                        ->select('id')->from('part_types')->whereRaw('UPPER(code) = ?', ['FG']))),
            ],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'items.*.qty_unit' => ['nullable', 'string', 'max:20'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ], [
            'items.required' => __('Tambahkan minimal satu baris picking.'),
            'items.*.part_id.exists' => __('Part yang dipilih bukan barang jadi (FG).'),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activeCustomers(): array
    {
        return Customer::query()
            ->where('is_active', true)
            ->orderBy('customer_name')
            ->get(['id', 'customer_code', 'customer_name'])
            ->map(fn (Customer $customer): array => [
                'id' => (int) $customer->id,
                'customer_code' => $customer->customer_code,
                'customer_name' => $customer->customer_name,
            ])
            ->all();
    }
}
