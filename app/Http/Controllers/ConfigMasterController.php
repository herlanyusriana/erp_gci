<?php

namespace App\Http\Controllers;

use App\Models\ConfigMaster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ConfigMasterController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ConfigMaster::class);

        $query = ConfigMaster::query()
            ->when($request->input('group'), fn ($q, $g) => $q->where('group', $g))
            ->when($request->input('search'), function ($q, $s) {
                $q->where(fn ($w) => $w->where('key', 'ilike', "%{$s}%"))
                    ->orWhere('description', 'ilike', "%{$s}%");
            });

        $configs = $query->orderBy('group')->orderBy('key')->paginate(30)->withQueryString();

        return Inertia::render('Administration/Config/Index', [
            'configs' => $configs,
            'filters' => $request->only('group', 'search'),
            'groups' => ConfigMaster::distinct()->orderBy('group')->pluck('group'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'group' => ['required', 'string', 'max:80'],
            'key' => ['required', 'string', 'max:120'],
            'value' => ['nullable', 'string'],
            'data_type' => ['required', 'in:string,boolean,integer,float,decimal,json'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['created_by'] = $request->user()?->id;
        ConfigMaster::updateOrCreate(
            ['group' => $data['group'], 'key' => $data['key']],
            array_diff_key($data, array_flip(['group', 'key'])),
        );

        return redirect()->route('config.index')->with('success', 'Config tersimpan.');
    }

    public function update(Request $request, ConfigMaster $config): RedirectResponse
    {
        $data = $request->validate([
            'group' => ['required', 'string', 'max:80'],
            'key' => ['required', 'string', 'max:120'],
            'value' => ['nullable', 'string'],
            'data_type' => ['required', 'in:string,boolean,integer,float,decimal,json'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['updated_by'] = $request->user()?->id;
        $config->update($data);

        return redirect()->route('config.index')->with('success', 'Config diperbarui.');
    }

    public function destroy(ConfigMaster $config): RedirectResponse
    {
        $config->delete();

        return redirect()->route('config.index')->with('success', 'Config dihapus.');
    }
}