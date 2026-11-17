<?php

namespace App\Http\Controllers;

use App\Models\Trolley;
use App\Support\TrolleyCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Master troli.
 *
 * Troli dipakai untuk memvalidasi hasil pindai loading dan memilih troli di
 * halaman pengiriman. Data awalnya berasal dari Export CSV aplikasi Trolley
 * Scan, tetapi setelah masuk ERP datanya dikelola di sini.
 */
class TrolleyController extends Controller
{
    public function index(Request $request): Response
    {
        $trolleys = Trolley::query()
            ->when($request->input('search'), fn ($q, $s) => $q
                ->where(fn ($w) => $w
                    ->where('code', 'ilike', "%{$s}%")
                    ->orWhere('location', 'ilike', "%{$s}%")
                    ->orWhere('notes', 'ilike', "%{$s}%")))
            ->when($request->input('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->when($request->input('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->input('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->input('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('code')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Master/Trolley/Index', [
            'trolleys' => $trolleys,
            'filters' => $request->only('search', 'kind', 'type', 'status'),
            'types' => Trolley::TYPES,
            'kinds' => collect(Trolley::KINDS)
                ->map(fn (string $kind): array => [
                    'value' => $kind,
                    'label' => Trolley::KIND_LABELS[$kind] ?? ucfirst($kind),
                ])
                ->all(),
            'total' => Trolley::query()->count(),
            'activeTotal' => Trolley::query()->active()->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules($request));

        Trolley::create($data + ['synced_at' => now()]);

        return redirect()
            ->route('trolleys.index')
            ->with('success', __('Troli :code ditambahkan.', ['code' => $data['code']]));
    }

    public function update(Request $request, Trolley $trolley): RedirectResponse
    {
        $data = $request->validate($this->rules($request, $trolley));

        $trolley->update($data);

        return redirect()
            ->route('trolleys.index')
            ->with('success', __('Troli :code diperbarui.', ['code' => $trolley->code]));
    }

    public function destroy(Trolley $trolley): RedirectResponse
    {
        // Riwayat pindai menyimpan kode sebagai teks, jadi menghapus master tidak
        // merusak dokumen lama. Troli yang masih dipakai tetap boleh dihapus
        // tetapi disarankan dinonaktifkan agar jejaknya tetap bisa dicari.
        $trolley->delete();

        return redirect()
            ->route('trolleys.index')
            ->with('success', __('Troli :code dihapus.', ['code' => $trolley->code]));
    }

    /**
     * Impor/segarkan dari Export CSV aplikasi Trolley Scan. Idempoten: baris
     * dicocokkan lewat `code`.
     */
    public function import(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ], [
            'file.required' => __('Pilih file CSV hasil Export dari aplikasi Trolley Scan.'),
            'file.mimes' => __('File harus berupa CSV.'),
        ]);

        try {
            $parsed = TrolleyCsv::parse($validated['file']->getRealPath());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $result = TrolleyCsv::store($parsed['rows'], $parsed['skipped']);

        return redirect()
            ->route('trolleys.index')
            ->with('success', __('Impor selesai: :created baru, :updated diperbarui, :skipped dilewati.', [
                'created' => $result['created'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'skipped' => $result['skipped'] ?? $parsed['skipped'],
            ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(Request $request, ?Trolley $ignore = null): array
    {
        return [
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('trolleys', 'code')->ignore($ignore?->id),
            ],
            'type' => ['required', Rule::in(Trolley::TYPES)],
            'kind' => ['nullable', Rule::in(Trolley::KINDS)],
            'capacity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'location' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
