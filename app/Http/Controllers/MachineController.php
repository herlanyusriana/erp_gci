<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MachineController extends Controller
{
    public function index(Request $request): Response
    {
        $machines = Machine::query()
            ->when($request->input('search'), fn ($q, $s) => $q
                ->where('machine_code', 'ilike', "%{$s}%")
                ->orWhere('machine_name', 'ilike', "%{$s}%"))
            ->orderBy('machine_code')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Master/Machine/Index', [
            'machines' => $machines,
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'machine_code' => ['required', 'string', 'max:80', 'unique:machines,machine_code'],
            'machine_name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['created_by'] = $request->user()?->id;
        Machine::create($data);

        return redirect()->route('machines.index')->with('success', __('Machine created.'));
    }

    public function update(Request $request, Machine $machine): RedirectResponse
    {
        $data = $request->validate([
            'machine_code' => ['required', 'string', 'max:80', "unique:machines,machine_code,{$machine->id}"],
            'machine_name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['updated_by'] = $request->user()?->id;
        $machine->update($data);

        return redirect()->route('machines.index')->with('success', __('Machine updated.'));
    }

    public function destroy(Machine $machine): RedirectResponse
    {
        $machine->delete();

        return redirect()->route('machines.index')->with('success', __('Machine deleted.'));
    }
}