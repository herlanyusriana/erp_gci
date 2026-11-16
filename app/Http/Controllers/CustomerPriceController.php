<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\Part;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master harga jual barang jadi per customer.
 *
 * Satu halaman = satu customer × seluruh FG aktif, disimpan sekaligus. Harga
 * yang dikosongkan berarti customer itu tidak punya harga untuk part tersebut.
 */
class CustomerPriceController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', CustomerPrice::class);

        $customers = Customer::query()
            ->where('is_active', true)
            ->orderBy('customer_name')
            ->get(['id', 'customer_code', 'customer_name']);

        $customerId = (int) ($request->input('customer_id') ?? $customers->first()?->id ?? 0);

        $parts = Part::query()
            ->whereHas('partType', fn ($q) => $q->whereRaw('UPPER(code) = ?', ['FG']))
            ->where('is_active', true)
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('part_number', 'ilike', "%{$s}%")
                ->orWhere('part_name', 'ilike', "%{$s}%")))
            ->with('uom:id,code')
            ->orderBy('part_number')
            ->get();

        $prices = CustomerPrice::query()
            ->where('customer_id', $customerId)
            ->whereIn('part_id', $parts->pluck('id'))
            ->get()
            ->keyBy('part_id');

        $parts = $parts->map(fn (Part $part): array => [
            'id' => (int) $part->id,
            'part_number' => (string) $part->part_number,
            'part_name' => (string) $part->part_name,
            'model' => $part->model,
            'uom' => $part->uom?->code,
            'price' => $prices->has($part->id) ? (float) $prices[$part->id]->price : null,
            'currency' => $prices->has($part->id) ? (string) $prices[$part->id]->currency : 'IDR',
            'is_active' => $prices->has($part->id) ? (bool) $prices[$part->id]->is_active : true,
        ])->values();

        return Inertia::render('Master/CustomerPrice/Index', [
            'parts' => $parts,
            'customers' => $customers,
            'filters' => [
                'customer_id' => $customerId > 0 ? $customerId : null,
                'search' => $request->input('search'),
            ],
            'currencies' => $this->currencies(),
            'filledCount' => CustomerPrice::query()->where('customer_id', $customerId)->count(),
        ]);
    }

    /** Simpan harga seluruh baris yang dikirim sekaligus. */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', CustomerPrice::class);

        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'prices' => ['required', 'array'],
            'prices.*.part_id' => ['required', 'integer', 'exists:parts,id'],
            'prices.*.price' => ['nullable', 'numeric', 'min:0'],
            'prices.*.currency' => ['nullable', 'string', 'max:10'],
            'prices.*.is_active' => ['nullable', 'boolean'],
        ], [
            'prices.required' => __('Tidak ada harga yang dikirim.'),
        ]);

        $customerId = (int) $validated['customer_id'];
        $userId = $request->user()?->id;
        $saved = 0;

        DB::transaction(function () use ($validated, $customerId, $userId, &$saved): void {
            foreach ($validated['prices'] as $row) {
                $partId = (int) $row['part_id'];
                $price = $row['price'] ?? null;

                // Harga dikosongkan: hapus baris agar tidak ada harga 0 palsu.
                if ($price === null || $price === '') {
                    CustomerPrice::query()
                        ->where('customer_id', $customerId)
                        ->where('part_id', $partId)
                        ->delete();

                    continue;
                }

                CustomerPrice::updateOrCreate(
                    ['customer_id' => $customerId, 'part_id' => $partId],
                    [
                        'price' => (float) $price,
                        'currency' => strtoupper((string) ($row['currency'] ?? 'IDR')) ?: 'IDR',
                        'is_active' => (bool) ($row['is_active'] ?? true),
                        'updated_by' => $userId,
                        'created_by' => $userId,
                    ],
                );

                $saved++;
            }
        });

        return redirect()
            ->route('customer-prices.index', ['customer_id' => $customerId])
            ->with('success', __(':count harga jual disimpan.', ['count' => $saved]));
    }

    /**
     * Mata uang yang lazim dipakai; bisa ditambah lewat isian bebas.
     *
     * @return list<string>
     */
    private function currencies(): array
    {
        $used = CustomerPrice::query()->distinct()->orderBy('currency')->pluck('currency')->all();

        return array_values(array_unique(array_filter([...['IDR', 'USD', 'KRW'], ...$used])));
    }
}
