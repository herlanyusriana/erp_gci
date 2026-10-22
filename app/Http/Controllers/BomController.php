<?php

namespace App\Http\Controllers;

use App\Models\Bom;
use App\Models\Machine;
use App\Models\Part;
use App\Models\Process;
use App\Support\UomCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BomController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Bom::class);

        $query = Bom::query()
            ->with(['part' => fn ($q) => $q->with('partType')])
            ->withCount('items')
            ->when($request->input('search'), function ($q, $search) {
                $q->whereHas('part', function ($w) use ($search) {
                    $w->where('part_number', 'ilike', "%{$search}%")
                        ->orWhere('part_name', 'ilike', "%{$search}%")
                        ->orWhere('model', 'ilike', "%{$search}%");
                });
            });

        $boms = $query
            ->orderBy('bom_no')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Master/Bom/Index', [
            'boms' => $boms,
            'filters' => $request->only('search'),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Bom::class);

        return Inertia::render('Master/Bom/Form', [
            'bom' => null,
            'fgParts' => Part::query()
                ->whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) = ?', ['fg']))
                ->whereNotIn('id', Bom::withTrashed()->select('part_id'))
                ->orderBy('part_number')
                ->get(['id', 'part_number', 'part_name', 'model', 'part_type_id']),
            'materials' => Part::query()
                ->with('uom:id,code')
                ->orderBy('part_number')
                ->get(['id', 'part_number', 'part_name', 'part_type_id', 'uom_id']),
            'processes' => Process::query()->orderBy('process_name')->get(['id', 'process_code', 'process_name']),
            'machines' => Machine::query()
                ->where('is_active', true)
                ->orderBy('machine_name')
                ->get(['id', 'machine_code', 'machine_name']),
            'uoms' => UomCatalog::codes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Bom::class);

        $data = $request->validate($this->rules());
        $partId = (int) $data['part_id'];

        $isFg = Part::query()
            ->whereKey($partId)
            ->whereHas('partType', fn ($q) => $q->whereRaw('LOWER(code) = ?', ['fg']))
            ->exists();
        if (! $isFg) {
            throw ValidationException::withMessages(['part_id' => __('BOM hanya untuk part FG.')]);
        }

        $bom = DB::transaction(function () use ($request, $data, $partId): Bom {
            if (Bom::withTrashed()->where('part_id', $partId)->exists()) {
                throw ValidationException::withMessages([
                    'part_id' => __('Part FG ini sudah memiliki BOM. Silakan ubah BOM yang ada.'),
                ]);
            }

            $actorId = $request->user()?->id;
            $bom = Bom::create([
                'part_id' => $partId,
                'bom_no' => Bom::generateBomNo(),
                'version' => $data['version'] ?? '1.0',
                'is_active' => true,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            foreach ($data['items'] as $item) {
                $bom->items()->create($this->normalizeItem($item) + [
                    'is_active' => true,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);
            }

            return $bom;
        });

        return redirect()->route('boms.show', $bom)->with('success', __('BOM dibuat.'));
    }

    /**
     * Field wajib per item masih Open Question PRD: saat ini hanya child part dan
     * jumlah yang wajib; sisanya opsional dan disimpan apa adanya.
     *
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'part_id' => [
                'required', 'integer', 'exists:parts,id',
                Rule::unique('boms', 'part_id')->withoutTrashed(),
            ],
            'version' => ['nullable', 'string', 'max:50'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.sequence' => ['nullable', 'integer', 'min:0'],
            'items.*.process_id' => ['nullable', 'integer', 'exists:processes,id'],
            'items.*.machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'items.*.parent_part_id' => ['nullable', 'integer', 'exists:parts,id'],
            'items.*.parent_part_name' => ['nullable', 'string', 'max:255'],
            'items.*.parent_qty' => ['nullable', 'numeric', 'min:0'],
            'items.*.parent_uom' => ['nullable', 'string', 'max:20'],
            'items.*.child_part_id' => ['required', 'integer', 'exists:parts,id'],
            'items.*.child_part_name' => ['nullable', 'string', 'max:255'],
            'items.*.size' => ['nullable', 'string', 'max:100'],
            'items.*.child_qty' => ['required', 'numeric', 'gt:0'],
            'items.*.uom_rm' => ['nullable', 'string', 'max:20'],
            'items.*.special_code' => ['nullable', 'string', 'max:50'],
            'items.*.source' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * Isi nama part dari master bila tidak dikirim, dan kosongkan nilai opsional
     * berupa string kosong agar tersimpan sebagai null.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item): array
    {
        $fields = [
            'sequence', 'process_id', 'machine_id',
            'parent_part_id', 'parent_part_name', 'parent_qty', 'parent_uom',
            'child_part_id', 'child_part_name', 'size', 'child_qty',
            'uom_rm', 'special_code', 'source',
        ];

        $normalized = [];
        foreach ($fields as $field) {
            $value = $item[$field] ?? null;
            $normalized[$field] = is_string($value) && trim($value) === '' ? null : $value;
        }

        $parts = Part::query()
            ->whereIn('id', array_filter([$normalized['child_part_id'], $normalized['parent_part_id']]))
            ->get(['id', 'part_number', 'part_name'])
            ->keyBy('id');

        if ($normalized['child_part_name'] === null) {
            $normalized['child_part_name'] = $parts[(int) $normalized['child_part_id']]?->part_name;
        }
        if ($normalized['parent_part_id'] !== null && $normalized['parent_part_name'] === null) {
            $normalized['parent_part_name'] = $parts[(int) $normalized['parent_part_id']]?->part_name;
        }

        return $normalized;
    }

    public function show(Bom $bom): Response
    {
        Gate::authorize('view', $bom);

        $bom->load([
            'part' => fn ($q) => $q->with('partType', 'uom'),
            'items' => fn ($q) => $q->with(['process', 'machine', 'parentPart', 'childPart']),
        ]);

        return Inertia::render('Master/Bom/Show', [
            'bom' => $bom,
        ]);
    }
}
