<?php

namespace App\Http\Controllers;

use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Machine;
use App\Models\MachineCycleTime;
use App\Models\Part;
use App\Models\Process;
use App\Support\DocumentNumber;
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
                ->whereHas('partType', fn ($q) => $q->whereIn(DB::raw('LOWER(code)'), ['fg', 'wip']))
                ->whereNotIn('id', Bom::withTrashed()->select('part_id'))
                ->orderBy('part_number')
                ->get(['id', 'part_number', 'part_name', 'model', 'part_type_id']),
            'materials' => Part::query()
                ->with('uom:id,code')
                ->orderBy('part_number')
                ->get(['id', 'part_number', 'part_name', 'model', 'size', 'part_type_id', 'uom_id']),
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

        $isFgOrWip = Part::query()
            ->whereKey($partId)
            ->whereHas('partType', fn ($q) => $q->whereIn(DB::raw('LOWER(code)'), ['fg', 'wip']))
            ->exists();
        if (! $isFgOrWip) {
            throw ValidationException::withMessages(['part_id' => __('BOM hanya untuk part FG atau WIP.')]);
        }

        $actorId = $request->user()?->id;
        $items = array_map(fn (array $item): array => $this->normalizeItem($item), $data['items']);

        $bom = DocumentNumber::retry(function () use ($data, $partId, $actorId, $items): Bom {
            return DB::transaction(function () use ($data, $partId, $actorId, $items): Bom {
                if (Bom::withTrashed()->where('part_id', $partId)->exists()) {
                    throw ValidationException::withMessages([
                        'part_id' => __('Part FG ini sudah memiliki BOM. Silakan ubah BOM yang ada.'),
                    ]);
                }

                $bom = Bom::create([
                    'part_id' => $partId,
                    'bom_no' => Bom::generateBomNo(),
                    'version' => $data['version'] ?? '1.0',
                    'is_active' => true,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);

                foreach ($items as $item) {
                    $bom->items()->create($item + [
                        'is_active' => true,
                        'created_by' => $actorId,
                        'updated_by' => $actorId,
                    ]);
                }

                return $bom;
            });
        }, 'boms_bom_no_unique');

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
            'items.*.child_qty' => [
                'required',
                'numeric',
                'min:0',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $index = explode('.', $attribute)[1] ?? null;
                    $outputType = $index !== null ? request()->input("items.{$index}.output_type", BomItem::OUTPUT_NORMAL) : BomItem::OUTPUT_NORMAL;
                    if ($outputType !== BomItem::OUTPUT_BYPRODUCT && (float) $value <= 0) {
                        $fail(__('validation.gt.numeric', ['attribute' => $attribute, 'value' => 0]));
                    }
                },
            ],
            'items.*.uom_rm' => ['nullable', 'string', 'max:20'],
            'items.*.special_code' => ['nullable', 'string', 'max:50'],
            'items.*.source' => ['nullable', 'string', 'max:50'],
            'items.*.output_type' => ['nullable', Rule::in(BomItem::OUTPUT_TYPES)],
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
            'uom_rm', 'special_code', 'source', 'output_type',
        ];

        $normalized = [];
        foreach ($fields as $field) {
            $value = $item[$field] ?? null;
            $normalized[$field] = is_string($value) && trim($value) === '' ? null : $value;
        }
        $normalized['output_type'] ??= BomItem::OUTPUT_NORMAL;

        $parts = Part::query()
            ->whereIn('id', array_filter([$normalized['child_part_id'], $normalized['parent_part_id']]))
            ->get(['id', 'part_number', 'part_name', 'size'])
            ->keyBy('id');

        if ($normalized['child_part_name'] === null) {
            $normalized['child_part_name'] = $parts[(int) $normalized['child_part_id']]?->part_name;
        }
        if ($normalized['parent_part_id'] !== null && $normalized['parent_part_name'] === null) {
            $normalized['parent_part_name'] = $parts[(int) $normalized['parent_part_id']]?->part_name;
        }

        // Size mengikuti master material (child part); placeholder `-` dianggap
        // kosong. Nilai kiriman hanya dipakai bila master belum punya size.
        $masterSize = trim((string) $parts[(int) $normalized['child_part_id']]?->size);
        if ($masterSize !== '' && $masterSize !== '-') {
            $normalized['size'] = $masterSize;
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

        // Lookup Tact Time & QDC otomatis dari master `machine_cycle_times` (Single Source of Truth).
        $machineIds = $bom->items->pluck('machine_id')->filter()->unique()->values();
        $parentPartIds = $bom->items->pluck('parent_part_id')->filter()->unique()->values();

        $cycleTimes = ($machineIds->isNotEmpty() && $parentPartIds->isNotEmpty())
            ? MachineCycleTime::query()
                ->where('is_active', true)
                ->whereIn('machine_id', $machineIds)
                ->whereIn('part_id', $parentPartIds)
                ->get(['machine_id', 'part_id', 'cycle_time_seconds', 'qdc_minutes'])
                ->keyBy(fn (MachineCycleTime $row): string => $row->machine_id.'|'.$row->part_id)
            : collect();

        $bom->items->each(function ($item) use ($cycleTimes): void {
            $ct = $cycleTimes->get(($item->machine_id ?? 0).'|'.($item->parent_part_id ?? 0));
            $item->setAttribute('tact_time_seconds', $ct?->cycle_time_seconds);
            $item->setAttribute('qdc_minutes', $ct?->qdc_minutes);
        });

        return Inertia::render('Master/Bom/Show', [
            'bom' => $bom,
        ]);
    }

    public function edit(Bom $bom): Response
    {
        Gate::authorize('update', $bom);

        $bom->load([
            'part:id,part_number,part_name,model',
            'items' => fn ($q) => $q->with(['process:id,process_code,process_name', 'machine:id,machine_code,machine_name', 'parentPart:id,part_number,part_name,model', 'childPart:id,part_number,part_name,model'])->orderBy('sequence'),
        ]);

        return Inertia::render('Master/Bom/Form', [
            'bom' => $bom,
            'items' => $bom->items,
            'fgParts' => Part::query()
                ->whereHas('partType', fn ($q) => $q->whereIn(DB::raw('LOWER(code)'), ['fg', 'wip']))
                ->whereKey($bom->part_id)
                ->orderBy('part_number')
                ->get(['id', 'part_number', 'part_name', 'model', 'part_type_id']),
            'materials' => Part::query()
                ->with('uom:id,code')
                ->orderBy('part_number')
                ->get(['id', 'part_number', 'part_name', 'model', 'size', 'part_type_id', 'uom_id']),
            'processes' => Process::query()->orderBy('process_name')->get(['id', 'process_code', 'process_name']),
            'machines' => Machine::query()
                ->where('is_active', true)
                ->orderBy('machine_name')
                ->get(['id', 'machine_code', 'machine_name']),
            'uoms' => UomCatalog::codes(),
        ]);
    }

    public function update(Request $request, Bom $bom): RedirectResponse
    {
        Gate::authorize('update', $bom);

        $data = $request->validate($this->updateRules());
        $partId = (int) $data['part_id'];

        $isFgOrWip = Part::query()
            ->whereKey($partId)
            ->whereHas('partType', fn ($q) => $q->whereIn(DB::raw('LOWER(code)'), ['fg', 'wip']))
            ->exists();
        if (! $isFgOrWip) {
            throw ValidationException::withMessages(['part_id' => __('BOM hanya untuk part FG atau WIP.')]);
        }

        DB::transaction(function () use ($request, $bom, $data, $partId): void {
            $ownerBomId = Bom::withTrashed()->where('part_id', $partId)->value('id');
            if ($ownerBomId !== null && (int) $ownerBomId !== $bom->id) {
                throw ValidationException::withMessages([
                    'part_id' => __('Part FG ini sudah memiliki BOM lain.'),
                ]);
            }

            $actorId = $request->user()?->id;
            $bom->update([
                'part_id' => $partId,
                'version' => $data['version'] ?? $bom->version,
                'updated_by' => $actorId,
            ]);

            $bom->items()->delete();

            foreach ($data['items'] as $item) {
                $bom->items()->create($this->normalizeItem($item) + [
                    'is_active' => true,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);
            }
        });

        $bom->refresh();

        return redirect()->route('boms.show', $bom)->with('success', __('BOM diperbarui.'));
    }

    /**
     * Aturan update sama dengan create, dengan dua pengecualian.
     *
     * 1. Keunikan part_id tidak divalidasi di sini karena BOM milik part itu
     *    sendiri harus tetap boleh disimpan; bentrok dengan BOM lain ditolak di
     *    dalam transaksi.
     * 2. `child_qty` menerima 0. Data BOM hasil impor memuat baris komponen
     *    berjumlah 0 (mis. FOAM PU BLACK & OPP TRANS pada BOM REINFORCE
     *    ASSEMBLY). Dengan `gt:0` BOM seperti itu tidak bisa disimpan sama
     *    sekali — bahkan saat operator hanya mengubah baris lain. Baris baru
     *    tetap wajib > 0 lewat `rules()` pada create.
     *
     * @return array<string, mixed>
     */
    private function updateRules(): array
    {
        $rules = $this->rules();
        $rules['part_id'] = ['required', 'integer', 'exists:parts,id'];
        $rules['items.*.child_qty'] = ['required', 'numeric', 'gte:0'];

        return $rules;
    }
}
