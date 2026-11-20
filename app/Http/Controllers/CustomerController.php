<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Customer::class);

        $customers = Customer::query()
            ->when($request->input('search'), fn ($q, $s) => $q
                ->where(fn ($w) => $w
                    ->where('customer_code', 'ilike', "%{$s}%")
                    ->orWhere('customer_name', 'ilike', "%{$s}%")
                    ->orWhere('short_name', 'ilike', "%{$s}%")
                    ->orWhere('npwp', 'ilike', "%{$s}%")))
            ->orderBy('customer_code')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Master/Customer/Index', [
            'customers' => $customers,
            'filters' => $request->only('search'),
            'bcFacilities' => Customer::BC_FACILITIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Customer::class);

        $data = $request->validate([
            'customer_code' => ['required', 'string', 'max:50', 'unique:customers,customer_code'],
            'customer_name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'npwp' => ['nullable', 'string', 'max:32'],
            'bc_facility' => ['nullable', Rule::in(Customer::BC_FACILITIES)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['created_by'] = $request->user()?->id;
        Customer::create($data);

        return redirect()->route('customers.index')->with('success', __('Customer created.'));
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        Gate::authorize('update', $customer);

        $data = $request->validate([
            'customer_code' => ['required', 'string', 'max:50', Rule::unique('customers', 'customer_code')->ignore($customer->id)],
            'customer_name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'npwp' => ['nullable', 'string', 'max:32'],
            'bc_facility' => ['nullable', Rule::in(Customer::BC_FACILITIES)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['updated_by'] = $request->user()?->id;
        $customer->update($data);

        return redirect()->route('customers.index')->with('success', __('Customer updated.'));
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        Gate::authorize('delete', $customer);

        if ($customer->deliveries()->exists()) {
            return back()->with('error', __('Customer masih dipakai pada dokumen pengiriman.'));
        }

        if ($customer->incomingArrivals()->exists()) {
            return back()->with('error', __('Customer masih dipakai pada dokumen kedatangan.'));
        }

        $customer->delete();

        return redirect()->route('customers.index')->with('success', __('Customer deleted.'));
    }
}
