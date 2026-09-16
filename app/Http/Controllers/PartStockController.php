<?php

namespace App\Http\Controllers;

use App\Models\PartStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PartStockController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PartStock::class);

        $stocks = PartStock::query()
            ->with(['part:id,part_number,part_name,part_type_id'])
            ->with('part.partType:id,code,name')
            ->when($request->input('search'), fn ($q, $s) => $q
                ->where('tag', 'ilike', "%{$s}%")
                ->orWhereHas('part', fn ($w) => $w->where('part_number', 'ilike', "%{$s}%")->orWhere('part_name', 'ilike', "%{$s}%")))
            ->orderBy('part_id')
            ->orderBy('tag')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Incoming/Stock/Index', [
            'stocks' => $stocks,
            'filters' => $request->only('search'),
        ]);
    }
}