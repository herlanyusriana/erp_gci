<?php

namespace App\Http\Controllers;

use App\Models\ConfigMaster;
use App\Models\IncomingReceive;
use App\Models\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LocationController extends Controller
{
    public function index(Request $request): Response
    {
        $locations = Location::query()
            ->when($request->input('search'), fn ($q, $s) => $q
                ->where('code', 'ilike', "%{$s}%")
                ->orWhere('name', 'ilike', "%{$s}%"))
            ->orderBy('code')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Master/Location/Index', [
            'locations' => $locations,
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:locations,code'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['created_by'] = $request->user()?->id;
        Location::create($data);

        return redirect()->route('locations.index')->with('success', __('Lokasi dibuat.'));
    }

    public function update(Request $request, Location $location): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', "unique:locations,code,{$location->id}"],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['updated_by'] = $request->user()?->id;
        $location->update($data);

        return redirect()->route('locations.index')->with('success', __('Lokasi diperbarui.'));
    }

    public function destroy(Location $location): RedirectResponse
    {
        // Lokasi yang sudah dipakai dokumen penerimaan tidak boleh dihapus, supaya
        // riwayat lama tetap menunjuk lokasi yang sah.
        if (IncomingReceive::query()->where('location_code', $location->code)->exists()) {
            return redirect()
                ->route('locations.index')
                ->with('error', __('Lokasi sudah dipakai penerimaan; nonaktifkan saja alih-alih menghapus.'));
        }

        $location->delete();

        return redirect()->route('locations.index')->with('success', __('Lokasi dihapus.'));
    }

    public function printLabel(Location $location)
    {
        return view('locations.label', [
            'location' => $location,
            'companyName' => ConfigMaster::getValue('SYSTEM', 'company_name', 'PT Geum Cheon Indo'),
        ]);
    }
}
