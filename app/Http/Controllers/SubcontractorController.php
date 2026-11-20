<?php

namespace App\Http\Controllers;

use App\Models\Subcontractor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SubcontractorController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Subcontractor::class);

        $subcontractors = Subcontractor::query()
            ->when($request->input('search'), fn ($q, $s) => $q
                ->where(fn ($w) => $w
                    ->where('subcontractor_code', 'ilike', "%{$s}%")
                    ->orWhere('subcontractor_name', 'ilike', "%{$s}%")
                    ->orWhere('npwp', 'ilike', "%{$s}%")
                    ->orWhere('process_notes', 'ilike', "%{$s}%")))
            ->orderBy('subcontractor_code')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Master/Subcontractor/Index', [
            'subcontractors' => $subcontractors,
            'filters' => $request->only('search'),
            'bcFacilities' => Subcontractor::BC_FACILITIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Subcontractor::class);

        $data = $request->validate([
            'subcontractor_code' => ['required', 'string', 'max:50', 'unique:subcontractors,subcontractor_code'],
            'subcontractor_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'npwp' => ['nullable', 'string', 'max:32'],
            'bc_facility' => ['nullable', Rule::in(Subcontractor::BC_FACILITIES)],
            'process_notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['created_by'] = $request->user()?->id;
        Subcontractor::create($data);

        return redirect()->route('subcontractors.index')->with('success', __('Subcontractor created.'));
    }

    public function update(Request $request, Subcontractor $subcontractor): RedirectResponse
    {
        Gate::authorize('update', $subcontractor);

        $data = $request->validate([
            'subcontractor_code' => ['required', 'string', 'max:50', Rule::unique('subcontractors', 'subcontractor_code')->ignore($subcontractor->id)],
            'subcontractor_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'npwp' => ['nullable', 'string', 'max:32'],
            'bc_facility' => ['nullable', Rule::in(Subcontractor::BC_FACILITIES)],
            'process_notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['updated_by'] = $request->user()?->id;
        $subcontractor->update($data);

        return redirect()->route('subcontractors.index')->with('success', __('Subcontractor updated.'));
    }

    public function destroy(Subcontractor $subcontractor): RedirectResponse
    {
        Gate::authorize('delete', $subcontractor);

        if ($subcontractor->incomingArrivals()->exists()) {
            return back()->with('error', __('Subcont masih dipakai pada dokumen kedatangan.'));
        }

        $subcontractor->delete();

        return redirect()->route('subcontractors.index')->with('success', __('Subcontractor deleted.'));
    }
}
