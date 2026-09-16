<?php

namespace App\Http\Controllers;

use App\Models\Bom;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BomController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Bom::class);

        $query = Bom::query()
            ->with(['part' => fn ($q) => $q->with('partType')])
            ->withCount('items')
            ->when($request->input('search'), function ($q, $search) {
                $q->whereHas('part', function ($w) use ($search) {
                    $w->where('part_number', 'ilike', "%{$search}%")
                        ->orWhere('part_name', 'ilike', "%{$search}%")
                        ->orWhere('model', 'ilike', "%{$search}%");
                });
            });

        $boms = $query
            ->orderBy('bom_no')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Master/Bom/Index', [
            'boms' => $boms,
            'filters' => $request->only('search'),
        ]);
    }

    public function show(Bom $bom): Response
    {
        Gate::authorize('view', $bom);

        $bom->load([
            'part' => fn ($q) => $q->with('partType', 'uom'),
            'items' => fn ($q) => $q->with(['process', 'machine', 'parentPart', 'childPart']),
        ]);

        return Inertia::render('Master/Bom/Show', [
            'bom' => $bom,
        ]);
    }
}