<?php

namespace App\Http\Controllers;

use App\Models\TruckingCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TruckingCompanyController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', TruckingCompany::class);

        $truckings = TruckingCompany::query()
            ->when($request->input('search'), fn ($q, $s) => $q
                ->where('company_code', 'ilike', "%{$s}%")
                ->orWhere('company_name', 'ilike', "%{$s}%"))
            ->orderBy('company_code')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Master/Trucking/Index', [
            'truckings' => $truckings,
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', TruckingCompany::class);

        $data = $request->validate([
            'company_code' => ['required', 'string', 'max:80', 'unique:trucking_companies,company_code'],
            'company_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['created_by'] = $request->user()?->id;
        TruckingCompany::create($data);

        return redirect()->route('trucking-companies.index')->with('success', __('Trucking company created.'));
    }

    public function update(Request $request, TruckingCompany $trucking): RedirectResponse
    {
        Gate::authorize('update', $trucking);

        $data = $request->validate([
            'company_code' => ['required', 'string', 'max:80', "unique:trucking_companies,company_code,{$trucking->id}"],
            'company_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['updated_by'] = $request->user()?->id;
        $trucking->update($data);

        return redirect()->route('trucking-companies.index')->with('success', __('Trucking company updated.'));
    }

    public function destroy(TruckingCompany $trucking): RedirectResponse
    {
        Gate::authorize('delete', $trucking);

        $trucking->delete();

        return redirect()->route('trucking-companies.index')->with('success', __('Trucking company deleted.'));
    }
}
