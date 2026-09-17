<?php

namespace App\Http\Controllers;

use App\Models\Process;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProcessController extends Controller
{
    public function index(Request $request): Response
    {
        $processes = Process::query()
            ->when($request->input('search'), fn ($q, $s) => $q
                ->where('process_code', 'ilike', "%{$s}%")
                ->orWhere('process_name', 'ilike', "%{$s}%"))
            ->orderBy('process_code')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Master/Process/Index', [
            'processes' => $processes,
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'process_code' => ['required', 'string', 'max:80', 'unique:processes,process_code'],
            'process_name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['created_by'] = $request->user()?->id;
        Process::create($data);

        return redirect()->route('processes.index')->with('success', __('Process created.'));
    }

    public function update(Request $request, Process $process): RedirectResponse
    {
        $data = $request->validate([
            'process_code' => ['required', 'string', 'max:80', "unique:processes,process_code,{$process->id}"],
            'process_name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['updated_by'] = $request->user()?->id;
        $process->update($data);

        return redirect()->route('processes.index')->with('success', __('Process updated.'));
    }

    public function destroy(Process $process): RedirectResponse
    {
        $process->delete();

        return redirect()->route('processes.index')->with('success', __('Process deleted.'));
    }
}