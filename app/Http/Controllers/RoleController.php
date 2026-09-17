<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Role::class);

        $roles = Role::query()
            ->withCount('users')
            ->with('permissions:id,name')
            ->when($request->input('search'), fn ($q, $s) => $q->where('name', 'ilike', "%{$s}%")->orWhere('label', 'ilike', "%{$s}%"))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Administration/Role/Index', [
            'roles' => $roles,
            'filters' => $request->only('search'),
            'permissions' => Permission::orderBy('module')->orderBy('name')->get(['id', 'name', 'module', 'label']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Role::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'unique:roles,name'],
            'label' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['exists:permissions,id'],
        ]);

        $role = Role::create([
            'name' => strtolower($data['name']),
            'label' => $data['label'] ?? null,
            'description' => $data['description'] ?? null,
        ]);
        $role->permissions()->sync($data['permissions'] ?? []);

        return redirect()->route('roles.index')->with('success', __('Role tersimpan.'));
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        Gate::authorize('update', $role);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'unique:roles,name,' . $role->id],
            'label' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['exists:permissions,id'],
        ]);

        $role->update([
            'name' => strtolower($data['name']),
            'label' => $data['label'] ?? null,
            'description' => $data['description'] ?? null,
        ]);
        $role->permissions()->sync($data['permissions'] ?? []);

        return redirect()->route('roles.index')->with('success', __('Role diperbarui.'));
    }

    public function destroy(Role $role): RedirectResponse
    {
        Gate::authorize('delete', $role);

        abort_if($role->name === 'super-admin', 400, __('Role super-admin tidak dapat dihapus.'));

        $role->delete();

        return redirect()->route('roles.index')->with('success', __('Role dihapus.'));
    }
}