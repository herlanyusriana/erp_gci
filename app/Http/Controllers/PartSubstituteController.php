<?php

namespace App\Http\Controllers;

use App\Models\Part;
use App\Models\PartPackingType;
use App\Models\PartSubstitute;
use App\Models\Supplier;
use App\Rules\PartTypeRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PartSubstituteController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PartSubstitute::class);

        $query = PartSubstitute::query()
            ->with([
                'part:id,part_number,part_name',
                'substitutePart:id,part_number,part_name,uom_id',
                'substitutePart.uom:id,code',
                'supplier:id,supplier_code,supplier_name',
            ])
            ->when($request->input('search'), function ($q, $search) {
                $q->where(fn ($w) => $w
                    ->whereHas('part', fn ($p) => $p->where('part_number', 'ilike', "%{$search}%")->orWhere('part_name', 'ilike', "%{$search}%"))
                    ->orWhereHas('substitutePart', fn ($p) => $p->where('part_number', 'ilike', "%{$search}%")->orWhere('part_name', 'ilike', "%{$search}%"))
                    ->orWhereHas('supplier', fn ($s) => $s->where('supplier_name', 'ilike', "%{$search}%")->orWhere('supplier_code', 'ilike', "%{$search}%")));
            })
            ->when($request->input('supplier_id'), fn ($q, $sid) => $q->where('supplier_id', $sid));

        $substitutes = $query->orderBy('id')->paginate(15)->withQueryString();

        return Inertia::render('Master/Substitute/Index', [
            'substitutes' => $substitutes,
            'filters' => $request->only('search', 'supplier_id'),
            'suppliers' => Supplier::orderBy('supplier_name')->get(['id', 'supplier_code', 'supplier_name']),
            'parts' => Part::whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) != ?', ['fg']))
                ->with('uom:id,code')
                ->orderBy('part_number')->get(['id', 'part_number', 'part_name', 'uom_id']),
            'packingTypes' => $this->packingTypeOptions(),
        ]);
    }

    /**
     * Pilihan packing type per part pengganti untuk form, digabungkan per part.
     *
     * @return array<int, list<string>> key = part_id, value = kode packing terurut
     */
    private function packingTypeOptions(): array
    {
        return PartPackingType::query()
            ->orderBy('part_id')
            ->orderBy('code')
            ->get(['part_id', 'code'])
            ->groupBy('part_id')
            ->map(fn ($rows) => $rows->pluck('code')->values()->all())
            ->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['size' => $this->normalizeSize($request->input('size'))]);
        $request->merge(['packing_type' => $this->normalizePackingType($request->input('packing_type'))]);

        $data = $request->validate([
            'part_id' => ['required', 'exists:parts,id', PartTypeRule::notFg()],
            'substitute_part_id' => ['required', 'exists:parts,id', 'different:part_id', PartTypeRule::notFg()],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'material_group' => ['nullable', 'string', 'max:255'],
            'size' => ['nullable', 'string', 'max:100'],
            'packing_type' => $this->packingTypeRules($request),
            'source' => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['created_by'] = $request->user()?->id;
        PartSubstitute::updateOrCreate(
            [
                'part_id' => $data['part_id'],
                'substitute_part_id' => $data['substitute_part_id'],
                'supplier_id' => $data['supplier_id'] ?? null,
            ],
            array_diff_key($data, array_flip(['part_id', 'substitute_part_id', 'supplier_id'])),
        );

        return redirect()->route('substitutes.index')->with('success', __('Substitute tersimpan.'));
    }

    public function update(Request $request, PartSubstitute $substitute): RedirectResponse
    {
        $request->merge(['size' => $this->normalizeSize($request->input('size'))]);
        $request->merge(['packing_type' => $this->normalizePackingType($request->input('packing_type'))]);

        $data = $request->validate([
            'part_id' => ['required', 'exists:parts,id', PartTypeRule::notFg()],
            'substitute_part_id' => ['required', 'exists:parts,id', 'different:part_id', PartTypeRule::notFg()],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'material_group' => ['nullable', 'string', 'max:255'],
            'size' => ['nullable', 'string', 'max:100'],
            'packing_type' => $this->packingTypeRules($request),
            'source' => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['updated_by'] = $request->user()?->id;
        $substitute->update($data);

        return redirect()->route('substitutes.index')->with('success', __('Substitute diperbarui.'));
    }

    /**
     * Ukuran kosong disimpan sebagai null, bukan string kosong atau tanda hubung.
     */
    private function normalizeSize(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizePackingType(mixed $value): ?string
    {
        $trimmed = strtoupper(trim((string) ($value ?? '')));

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Packing type wajib dan harus terdaftar pada katalog part pengganti.
     *
     * @return list<mixed>
     */
    private function packingTypeRules(Request $request): array
    {
        $substitutePartId = (int) $request->input('substitute_part_id');

        return [
            'required',
            'string',
            'max:20',
            Rule::exists('part_packing_types', 'code')->where('part_id', $substitutePartId),
        ];
    }

    /**
     * Tambahkan packing type baru untuk satu part pengganti.
     *
     * Nilai disimpan sebagai pilihan part tersebut, bukan sebagai transaksi
     * kedatangan/invoice.
     */
    public function storePackingType(Request $request): RedirectResponse
    {
        Gate::authorize('create', PartSubstitute::class);

        $data = $request->validate([
            'part_id' => ['required', 'exists:parts,id'],
            'code' => ['required', 'string', 'max:20'],
        ]);

        PartPackingType::register((int) $data['part_id'], (string) $data['code']);

        return back()->with('success', __('Packing type ditambahkan.'));
    }

    public function destroy(PartSubstitute $substitute): RedirectResponse
    {
        $substitute->delete();

        return redirect()->route('substitutes.index')->with('success', __('Substitute dihapus.'));
    }
}
