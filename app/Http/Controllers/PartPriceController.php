<?php

namespace App\Http\Controllers;

use App\Models\Part;
use App\Models\PartPrice;
use App\Models\PartSubstitute;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PartPriceController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PartPrice::class);

        $prices = PartPrice::query()
            ->with(['supplier:id,supplier_code,supplier_name', 'part:id,part_number,part_name'])
            ->when($request->input('supplier_id'), fn ($q, $sid) => $q->where('supplier_id', $sid))
            ->when($request->input('search'), function ($q, $search) {
                $q->where(fn ($w) => $w
                    ->whereHas('supplier', fn ($s) => $s
                        ->where('supplier_name', 'ilike', "%{$search}%")
                        ->orWhere('supplier_code', 'ilike', "%{$search}%"))
                    ->orWhereHas('part', fn ($p) => $p
                        ->where('part_number', 'ilike', "%{$search}%")
                        ->orWhere('part_name', 'ilike', "%{$search}%")));
            })
            ->orderByDesc('valid_from')
            ->orderBy('part_id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Master/Price/Index', [
            'prices' => $prices,
            'filters' => $request->only('search', 'supplier_id'),
            'suppliers' => Supplier::query()
                ->where('is_active', true)
                ->orderBy('supplier_name')
                ->get(['id', 'supplier_code', 'supplier_name']),
            'parts' => Part::query()
                ->where('is_active', true)
                ->orderBy('part_number')
                ->get(['id', 'part_number', 'part_name']),
            'supplierParts' => PartSubstitute::supplierParts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', PartPrice::class);

        $data = $request->validate($this->rules($request), $this->messages());

        PartPrice::create($data + [
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return redirect()->route('prices.index')->with('success', __('Price created.'));
    }

    public function update(Request $request, PartPrice $price): RedirectResponse
    {
        Gate::authorize('update', $price);

        $data = $request->validate($this->rules($request, $price), $this->messages());

        $price->update($data + ['updated_by' => $request->user()?->id]);

        return redirect()->route('prices.index')->with('success', __('Price updated.'));
    }

    public function destroy(PartPrice $price): RedirectResponse
    {
        Gate::authorize('delete', $price);

        $price->delete();

        return redirect()->route('prices.index')->with('success', __('Price deleted.'));
    }

    /**
     * Unik per (supplier, part, valid_from) — diwakili lewat kolom supplier_id.
     *
     * @return array<string, mixed>
     */
    private function rules(Request $request, ?PartPrice $ignore = null): array
    {
        return [
            'supplier_id' => [
                'required', 'integer', 'exists:suppliers,id',
                Rule::unique('part_prices', 'supplier_id')
                    ->where(fn ($q) => $q
                        ->where('part_id', (int) $request->input('part_id'))
                        ->where('valid_from', (string) $request->input('valid_from')))
                    ->ignore($ignore?->id),
            ],
            'part_id' => ['required', 'integer', 'exists:parts,id', Rule::exists('part_substitutes', 'substitute_part_id')->where(fn ($q) => $q->where('supplier_id', (int) $request->input('supplier_id'))->where('is_active', true))],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:10'],
            'valid_from' => ['required', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'supplier_id.unique' => __('Harga untuk supplier + part + tanggal ini sudah ada.'),
        ];
    }
}
