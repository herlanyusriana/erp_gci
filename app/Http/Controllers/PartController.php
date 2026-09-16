<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePartRequest;
use App\Models\Part;
use App\Models\PartType;
use App\Models\Uom;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PartController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Part::class);

        $query = Part::query()
            ->with(['partType', 'uom'])
            ->when($request->input('type'), fn ($q, $type) => $q->whereHas('partType', fn ($t) => $t->where('code', $type)))
            ->when($request->input('search'), function ($q, $search) {
                $q->where(fn ($w) => $w
                    ->where('part_number', 'ilike', "%{$search}%")
                    ->orWhere('part_name', 'ilike', "%{$search}%")
                    ->orWhere('model', 'ilike', "%{$search}%"));
            });

        $parts = $query->orderBy('part_number')->paginate(10)->withQueryString();

        return Inertia::render('Master/Part/Index', [
            'parts' => $parts,
            'filters' => $request->only('search', 'type'),
            'partTypes' => PartType::orderBy('code')->get(),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Part::class);

        return Inertia::render('Master/Part/Form', [
            'part' => null,
            'partTypes' => PartType::orderBy('code')->get(),
            'uoms' => Uom::where('is_active', true)->orderBy('code')->get(),
            'defaultType' => $request->input('type'),
        ]);
    }

    public function store(StorePartRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()?->id;

        Part::create($data);

        return redirect()->route('parts.index')->with('success', 'Part created.');
    }

    public function edit(Part $part): Response
    {
        Gate::authorize('update', $part);

        return Inertia::render('Master/Part/Form', [
            'part' => $part->load(['partType', 'uom']),
            'partTypes' => PartType::orderBy('code')->get(),
            'uoms' => Uom::where('is_active', true)->orderBy('code')->get(),
            'defaultType' => $part->partType?->code,
        ]);
    }

    public function update(StorePartRequest $request, Part $part): RedirectResponse
    {
        $data = $request->validated();
        $data['updated_by'] = $request->user()?->id;

        $part->update($data);

        return redirect()->route('parts.index')->with('success', 'Part updated.');
    }

    public function destroy(Part $part): RedirectResponse
    {
        Gate::authorize('delete', $part);

        $part->delete();

        return redirect()->route('parts.index')->with('success', 'Part deleted.');
    }
}