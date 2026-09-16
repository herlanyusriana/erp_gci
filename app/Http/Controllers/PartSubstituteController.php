<?php

namespace App\Http\Controllers;

use App\Models\PartSubstitute;
use App\Models\Part;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PartSubstituteController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PartSubstitute::class);

        $query = PartSubstitute::query()
            ->with(['part:id,part_number,part_name', 'substitutePart:id,part_number,part_name', 'supplier:id,supplier_code,supplier_name'])
            ->when($request->input('search'), function ($q, $search) {
                $q->where(fn ($w) => $w
                    ->whereHas('part', fn ($p) => $p->where('part_number', 'ilike', "%{$search}%")->orWhere('part_name', 'ilike', "%{$search}%"))
                    ->orWhereHas('substitutePart', fn ($p) => $p->where('part_number', 'ilike', "%{$search}%")->orWhere('part_name', 'ilike', "%{$search}%"))
                    ->orWhereHas('supplier', fn ($s) => $s->where('supplier_name', 'ilike', "%{$search}%")));
            })
            ->when($request->input('supplier_id'), fn ($q, $sid) => $q->where('supplier_id', $sid));

        $substitutes = $query->orderBy('id')->paginate(15)->withQueryString();

        return Inertia::render('Master/Substitute/Index', [
            'substitutes' => $substitutes,
            'filters' => $request->only('search', 'supplier_id'),
            'suppliers' => Supplier::orderBy('supplier_name')->get(['id', 'supplier_code', 'supplier_name']),
            'parts' => Part::orderBy('part_number')->get(['id', 'part_number', 'part_name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'part_id' => ['required', 'exists:parts,id'],
            'substitute_part_id' => ['required', 'exists:parts,id', 'different:part_id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'material_group' => ['nullable', 'string', 'max:255'],
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

        return redirect()->route('substitutes.index')->with('success', 'Substitute tersimpan.');
    }

    public function update(Request $request, PartSubstitute $substitute): RedirectResponse
    {
        $data = $request->validate([
            'part_id' => ['required', 'exists:parts,id'],
            'substitute_part_id' => ['required', 'exists:parts,id', 'different:part_id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'material_group' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['updated_by'] = $request->user()?->id;
        $substitute->update($data);

        return redirect()->route('substitutes.index')->with('success', 'Substitute diperbarui.');
    }

    public function destroy(PartSubstitute $substitute): RedirectResponse
    {
        $substitute->delete();

        return redirect()->route('substitutes.index')->with('success', 'Substitute dihapus.');
    }
}