<?php

namespace App\Http\Controllers;

use App\Models\Uom;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UomController extends Controller
{
    public function index(Request $request): Response
    {
        $uoms = Uom::query()
            ->when($request->input('search'), fn ($q, $s) => $q
                ->where('code', 'ilike', "%{$s}%")
                ->orWhere('name', 'ilike', "%{$s}%"))
            ->orderBy('code')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Master/Uom/Index', [
            'uoms' => $uoms,
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:uoms,code'],
            'name' => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        Uom::create($data);

        return redirect()->route('uoms.index')->with('success', __('UOM created.'));
    }

    public function update(Request $request, Uom $uom): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', "unique:uoms,code,{$uom->id}"],
            'name' => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $uom->update($data);

        return redirect()->route('uoms.index')->with('success', __('UOM updated.'));
    }

    public function destroy(Uom $uom): RedirectResponse
    {
        $uom->delete();

        return redirect()->route('uoms.index')->with('success', __('UOM deleted.'));
    }
}