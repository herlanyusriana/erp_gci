<?php

namespace App\Http\Controllers;

use App\Models\IncomingReceive;
use App\Models\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Menata lokasi rak untuk penerimaan yang belum berlokasi.
 *
 * Pekerjaan ini administratif dan lebih nyaman di web, sehingga APK hanya
 * melakukan scan lokasi saat pengeluaran.
 */
class LocationSetupController extends Controller
{
    public function index(): Response
    {
        $receives = IncomingReceive::query()
            ->with([
                'part:id,part_number,part_name',
                'arrivalItem.arrival:id,arrival_no,invoice_no',
            ])
            ->whereNull('location_code')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Master/Location/Setup', [
            'receives' => $receives,
            'locations' => Location::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['code', 'name']),
        ]);
    }

    public function assign(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'location_code' => ['required', 'string', 'max:40', Rule::exists('locations', 'code')->where('is_active', true)],
            'receive_ids' => ['required', 'array', 'min:1'],
            'receive_ids.*' => ['integer', 'exists:incoming_receives,id'],
        ]);

        $updated = IncomingReceive::query()
            ->whereIn('id', $data['receive_ids'])
            ->update(['location_code' => $data['location_code']]);

        return redirect()
            ->route('locations.setup')
            ->with('success', __(':count penerimaan ditetapkan ke lokasi :code.', [
                'count' => $updated,
                'code' => $data['location_code'],
            ]));
    }
}
