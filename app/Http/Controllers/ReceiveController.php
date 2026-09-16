<?php

namespace App\Http\Controllers;

use App\Models\IncomingArrival;
use App\Models\IncomingArrivalItem;
use App\Models\IncomingReceive;
use App\Services\ReceiveMaterialService;
use App\Support\QrSvg;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ReceiveController extends Controller
{
    public function __construct(protected ReceiveMaterialService $receiveService) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', IncomingReceive::class);

        $arrivals = IncomingArrival::query()
            ->with(['supplier:id,supplier_code,supplier_name', 'items.receives'])
            ->get()
            ->map(function (IncomingArrival $arrival) {
                $remaining = $arrival->items->sum(fn ($item) => $this->receiveService->remainingQty($item));
                $arrival->remaining_qty = $remaining;
                $arrival->pending_items_count = $arrival->items->filter(fn ($item) => $this->receiveService->remainingQty($item) > 0)->count();

                return $arrival;
            })
            ->filter(fn ($arrival) => $arrival->remaining_qty > 0)
            ->values();

        return Inertia::render('Incoming/Receive/Index', [
            'pendingArrivals' => $arrivals,
        ]);
    }

    public function create(IncomingArrivalItem $arrivalItem): Response
    {
        Gate::authorize('create', IncomingReceive::class);

        $arrivalItem->load(['part', 'arrival.supplier', 'receives']);
        $remaining = $this->receiveService->remainingQty($arrivalItem);
        $totalReceived = $this->receiveService->receivedQuantity($arrivalItem);

        return Inertia::render('Incoming/Receive/Form', [
            'arrivalItem' => $arrivalItem,
            'remainingQty' => $remaining,
            'totalPlanned' => $this->receiveService->plannedQuantity($arrivalItem),
            'totalReceived' => $totalReceived,
            'weightBasis' => $this->receiveService->usesWeightBasis($arrivalItem),
            'isLocal' => (bool) $arrivalItem->arrival?->is_local,
        ]);
    }

    public function store(Request $request, IncomingArrivalItem $arrivalItem): RedirectResponse
    {
        $arrivalItem->loadMissing(['arrival', 'part']);
        Gate::authorize('create', IncomingReceive::class);

        $weightBasis = $this->receiveService->usesWeightBasis($arrivalItem);

        $validated = $request->validate([
            'receive_date' => ['required', 'date'],
            'truck_no' => ['nullable', 'string', 'max:50'],
            'tags' => ['required', 'array', 'min:1'],
            'tags.*.tag' => ['required', 'string', 'max:255'],
            'tags.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'tags.*.bundle_qty' => ['nullable', 'numeric', 'min:0'],
            'tags.*.bundle_unit' => ['nullable', 'in:PALLET,BUNDLE,BOX,BAG,ROLL,PACKAGES'],
            'tags.*.net_weight' => $weightBasis
                ? ['required', 'numeric', 'min:0.0001']
                : ['nullable', 'numeric'],
            'tags.*.gross_weight' => ['nullable', 'numeric'],
            'tags.*.qty_unit' => ['required', 'string', 'max:20'],
        ]);

        $this->receiveService->ensureTagsUniqueForArrivalItem($arrivalItem, $validated['tags'], 'tags');

        // Total yang diminta sesuai basis item (KGM untuk incoming, qty untuk local).
        $totalRequested = $weightBasis
            ? collect($validated['tags'])->sum(fn ($t) => (float) ($t['net_weight'] ?? $t['qty']))
            : collect($validated['tags'])->sum('qty');
        $remainingQty = $this->receiveService->remainingQty($arrivalItem);
        $unitLabel = $weightBasis ? 'KGM' : strtoupper((string) ($arrivalItem->unit_goods ?? 'qty'));

        if ($totalRequested > $remainingQty + 1e-9) {
            return back()->withInput()->withErrors([
                'tags' => "Total ({$totalRequested} {$unitLabel}) melebihi sisa ({$remainingQty} {$unitLabel}).",
            ]);
        }

        $goodsUnit = strtoupper((string) ($arrivalItem->unit_goods ?? 'KGM'));
        $receiveAt = \Illuminate\Support\Carbon::parse($validated['receive_date'])->setTimeFromTimeString(now()->format('H:i:s'));
        $truckNo = trim((string) ($validated['truck_no'] ?? '')) !== '' ? strtoupper(trim((string) $validated['truck_no'])) : null;

        DB::transaction(function () use ($validated, $arrivalItem, $goodsUnit, $receiveAt, $truckNo, $weightBasis) {
            $partId = $this->receiveService->resolvePartId($arrivalItem);

            foreach ($validated['tags'] as $tagData) {
                if (strtoupper((string) $tagData['qty_unit']) !== $goodsUnit) {
                    throw ValidationException::withMessages([
                        'tags' => "Unit qty tidak sesuai. Item ini menggunakan unit {$goodsUnit}.",
                    ]);
                }

                // Berat: basis KGM wajib; basis qty (local) biarkan null → stok pakai qty.
                $netWeight = $weightBasis ? (float) ($tagData['net_weight'] ?? $tagData['qty']) : null;

                $tag = $this->receiveService->normalizeTag($tagData['tag'] ?? null);

                $receive = $arrivalItem->receives()->create([
                    'part_id' => $partId,
                    'tag' => $tag,
                    'qty' => $tagData['qty'],
                    'bundle_qty' => $tagData['bundle_qty'] ?? null,
                    'bundle_unit' => $tagData['bundle_unit'] ?? null,
                    'weight' => $netWeight,
                    'net_weight' => $netWeight,
                    'gross_weight' => $tagData['gross_weight'] ?? null,
                    'qty_unit' => $goodsUnit,
                    'qc_status' => 'pass',
                    'invoice_no' => $arrivalItem->arrival?->invoice_no,
                    'truck_no' => $truckNo,
                    'ata_date' => $receiveAt,
                ]);

                $resolved = $this->receiveService->resolveReceiveTag($tag, (int) $receive->id, $receiveAt);
                if ($resolved !== null && $receive->tag !== $resolved) {
                    $receive->update(['tag' => $resolved]);
                }

                $this->receiveService->postStock($receive);
            }
        });

        $arrival = $arrivalItem->arrival()->with('items.receives')->first();
        if ($arrival) {
            $isComplete = !$this->receiveService->hasPendingReceives($arrival);
            if ($isComplete && empty($arrival->transaction_no)) {
                $arrival->transaction_no = IncomingArrival::generateTransactionNo($receiveAt->toDateString());
                $arrival->save();
            }
            $message = $isComplete
                ? 'Invoice complete receive. Transaction No: ' . $arrival->transaction_no
                : 'TAG tersimpan. Masih ada pending.';

            return redirect()
                ->route(($arrival->is_local ? 'local-pos.show' : 'incoming-arrivals.show'), $arrival)
                ->with('success', $message);
        }

        return redirect()->route('receive.index')->with('success', 'TAG tersimpan.');
    }

    public function printLabel(IncomingReceive $receive)
    {
        $receive->load([
            'arrivalItem.part',
            'arrivalItem.arrival.supplier',
        ]);

        $arrivalItem = $receive->arrivalItem;
        $arrival = $arrivalItem?->arrival;
        $part = $arrivalItem?->part;

        $receivedAt = $receive->ata_date ?? now();
        $monthNumber = (int) $receivedAt->format('m');

        // Unit & qty teks menyesuaikan basis item (KGM untuk incoming, qty untuk local).
        $goodsUnit = strtoupper(trim((string) ($arrivalItem?->unit_goods ?? $receive->qty_unit ?? '')));
        $weightBasis = $this->receiveService->usesWeightBasisItem($receive);

        // Payload QR — standar scan app: identify part + tag + receive.
        $payload = [
            'tag' => (string) ($receive->tag ?? ''),
            'receive_id' => (int) $receive->id,
            'part_id' => (int) ($arrivalItem?->part_id ?? 0),
            'part_no' => (string) ($part?->part_number ?? ''),
            'part_name' => (string) ($part?->part_name ?? ''),
            'qty' => (float) $receive->qty,
            'net_weight' => (float) ($receive->net_weight ?? 0),
            'qty_unit' => (string) ($receive->qty_unit ?? $arrivalItem?->unit_goods ?? ''),
            'invoice' => (string) ($arrival?->invoice_no ?? '-'),
            'supplier' => (string) ($arrival?->supplier?->supplier_name ?? '-'),
        ];

        $qrSvg = QrSvg::make(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 390, 0);

        return view('receives.label', compact('receive', 'arrivalItem', 'arrival', 'part', 'qrSvg', 'monthNumber', 'goodsUnit', 'weightBasis'));
    }

    public function edit(IncomingReceive $receive): Response
    {
        Gate::authorize('update', $receive);

        $receive->load(['arrivalItem.part', 'arrivalItem.arrival.supplier']);

        return Inertia::render('Incoming/Receive/Edit', [
            'receive' => $receive,
            'arrivalItem' => $receive->arrivalItem,
            'weightBasis' => $this->receiveService->usesWeightBasisItem($receive),
            'isLocal' => (bool) $receive->arrivalItem?->arrival?->is_local,
        ]);
    }

    public function update(Request $request, IncomingReceive $receive): RedirectResponse
    {
        $receive->load(['arrivalItem.arrival', 'arrivalItem.part']);
        Gate::authorize('update', $receive);

        $weightBasis = $this->receiveService->usesWeightBasisItem($receive);

        $validated = $request->validate([
            'receive_date' => ['required', 'date'],
            'tag' => ['nullable', 'string', 'max:255'],
            'truck_no' => ['nullable', 'string', 'max:50'],
            'qty' => ['required', 'numeric', 'min:0.0001'],
            'bundle_qty' => ['nullable', 'numeric', 'min:0'],
            'bundle_unit' => ['nullable', 'in:PALLET,BUNDLE,BOX,BAG,ROLL,PACKAGES'],
            'net_weight' => $weightBasis
                ? ['required', 'numeric', 'min:0.0001']
                : ['nullable', 'numeric'],
            'gross_weight' => ['nullable', 'numeric'],
        ]);

        $arrivalItem = $receive->arrivalItem;
        $goodsUnit = strtoupper((string) ($arrivalItem->unit_goods ?? 'KGM'));

        $tag = $this->receiveService->normalizeTag($validated['tag'] ?? null);
        if ($tag !== null) {
            $this->receiveService->ensureTagsUniqueForArrivalItem($arrivalItem, [['tag' => $tag]], 'tag', (int) $receive->id);
        }

        $receiveAt = \Illuminate\Support\Carbon::parse($validated['receive_date'])->setTimeFromTimeString(now()->format('H:i:s'));
        $netWeight = $weightBasis ? (float) ($validated['net_weight'] ?? $validated['qty']) : null;

        DB::transaction(function () use ($receive, $validated, $tag, $goodsUnit, $receiveAt, $netWeight) {
            // Reverse old stock contribution before mutating.
            $this->receiveService->reverseStock($receive);

            $receive->update([
                'tag' => $tag,
                'qty' => $validated['qty'],
                'bundle_qty' => $validated['bundle_qty'] ?? null,
                'bundle_unit' => $validated['bundle_unit'] ?? null,
                'weight' => $netWeight,
                'net_weight' => $netWeight,
                'gross_weight' => $validated['gross_weight'] ?? null,
                'qty_unit' => $goodsUnit,
                'qc_status' => 'pass',
                'truck_no' => trim((string) ($validated['truck_no'] ?? '')) !== '' ? strtoupper(trim((string) $validated['truck_no'])) : null,
                'ata_date' => $receiveAt,
            ]);

            $this->receiveService->postStock($receive);
        });

        $route = $arrivalItem->arrival?->is_local ? 'local-pos.show' : 'incoming-arrivals.show';

        return redirect()
            ->route($route, $arrivalItem->arrival)
            ->with('success', 'Receive updated.');
    }

    public function destroy(IncomingReceive $receive): RedirectResponse
    {
        $receive->load(['arrivalItem.arrival']);
        Gate::authorize('delete', $receive);

        DB::transaction(function () use ($receive) {
            $this->receiveService->reverseStock($receive);
            $receive->delete();
        });

        $route = $receive->arrivalItem->arrival?->is_local ? 'local-pos.show' : 'incoming-arrivals.show';

        return redirect()
            ->route($route, $receive->arrivalItem->arrival)
            ->with('success', "Receive {$receive->tag} deleted.");
    }
}