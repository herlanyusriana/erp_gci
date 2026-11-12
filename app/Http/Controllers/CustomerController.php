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
                    ->orWhere('customer_name', 'ilike', "%{$s}%")))
            ->orderBy('customer_code')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Master/Customer/Index', [
            'customers' => $customers,
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Customer::class);

        $data = $request->validate([
            'customer_code' => ['required', 'string', 'max:50', 'unique:customers,customer_code'],
            'customer_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
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
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
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

        $customer->delete();

        return redirect()->route('customers.index')->with('success', __('Customer deleted.'));
    }
}
