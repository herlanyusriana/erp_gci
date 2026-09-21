<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Supplier::class);

        $suppliers = Supplier::query()
            ->when($request->input('search'), fn ($q, $s) => $q
                ->where('supplier_code', 'ilike', "%{$s}%")
                ->orWhere('supplier_name', 'ilike', "%{$s}%"))
            ->orderBy('supplier_code')
            ->paginate(10)
            ->withQueryString();

        // Signature URL untuk dipakai di tabel/form (rawat gambar ttd).
        $suppliers->getCollection()->transform(fn ($s) => $s->append('signature_url'));

        return Inertia::render('Master/Supplier/Index', [
            'suppliers' => $suppliers,
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_code' => ['required', 'string', 'max:80', 'unique:suppliers,supplier_code'],
            'supplier_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:120'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'bank_account' => ['nullable', 'string', 'max:255'],
            'signature' => ['nullable', 'image', 'max:1024'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['signature_path'] = $this->storeSignature($request);
        unset($data['signature']);

        $data['created_by'] = $request->user()?->id;
        $supplier = Supplier::create($data);

        return redirect()->route('suppliers.index')->with('success', __('Supplier created.'));
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $request->validate([
            'supplier_code' => ['required', 'string', 'max:80', "unique:suppliers,supplier_code,{$supplier->id}"],
            'supplier_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:120'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'bank_account' => ['nullable', 'string', 'max:255'],
            'signature' => ['nullable', 'image', 'max:1024'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($request->hasFile('signature')) {
            // Hapus file lama lalu simpan yang baru.
            if ($supplier->signature_path) {
                Storage::disk('public')->delete($supplier->signature_path);
            }
            $data['signature_path'] = $this->storeSignature($request);
        }
        unset($data['signature']);

        $data['updated_by'] = $request->user()?->id;
        $supplier->update($data);

        return redirect()->route('suppliers.index')->with('success', __('Supplier updated.'));
    }

    /**
     * Simpan gambar ttd ke storage/app/public/suppliers dan kembalikan path relatif.
     */
    private function storeSignature(Request $request): ?string
    {
        if (! $request->hasFile('signature')) {
            return null;
        }

        $file = $request->file('signature');
        $name = 'sig-'.Str::random(20).'.'.$file->getClientOriginalExtension();

        return $file->storePubliclyAs('suppliers', $name, 'public');
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $supplier->delete();

        return redirect()->route('suppliers.index')->with('success', __('Supplier deleted.'));
    }
}
