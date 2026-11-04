<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProductionReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * API untuk penerimaan material oleh production di APK Material Tracker.
 *
 * Endpoint:
 * - POST /api/receipts/confirm  — catat penerimaan tag + mesin
 * - GET  /api/receipts          — daftar penerimaan per mesin
 */
class ProductionReceiptController extends Controller
{
    public function __construct(
        private ProductionReceiptService $receiptService,
    ) {}

    public function confirm(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()?->hasPermission('stock.issue') ?? false,
            403,
            __('Tidak berwenang mengakses data stok.'),
        );

        $data = $request->validate([
            'machine_id' => ['required', 'integer', 'exists:machines,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'tag' => ['nullable', 'string', 'max:255'],
            'material_issue_item_id' => ['nullable', 'integer', 'exists:material_issue_items,id'],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.material_issue_item_id' => ['nullable', 'integer', 'exists:material_issue_items,id'],
            'items.*.tag' => ['nullable', 'string', 'max:255'],
        ]);

        if (empty($data['items']) && empty($data['material_issue_item_id']) && empty($data['tag'])) {
            throw ValidationException::withMessages([
                'items' => __('Pilih minimal satu tag material untuk diterima.'),
            ]);
        }

        try {
            if (! empty($data['items'])) {
                $receipts = $this->receiptService->confirmBatch(
                    items: $data['items'],
                    machineId: (int) $data['machine_id'],
                    userId: (int) $request->user()->id,
                    notes: $data['notes'] ?? null,
                );

                $first = $receipts[0];
                $first->loadMissing(['part:id,part_number,part_name,model,size']);

                return response()->json([
                    'ok' => true,
                    'message' => __(':count material diterima di mesin.', ['count' => count($receipts)]),
                    'data' => [
                        'id' => $first->id,
                        'count' => count($receipts),
                        'machine_id' => (int) $data['machine_id'],
                        'tag' => implode(', ', array_map(fn ($r) => $r->tag, $receipts)),
                        'part_id' => $first->part_id,
                        'part' => $first->part ? [
                            'part_number' => $first->part->part_number,
                            'part_name' => $first->part->part_name,
                            'model' => $first->part->model,
                            'size' => $first->part->size,
                        ] : null,
                        'receipts' => array_map(fn ($r) => [
                            'id' => $r->id,
                            'material_issue_item_id' => $r->material_issue_item_id,
                            'tag' => $r->tag,
                            'part_id' => $r->part_id,
                        ], $receipts),
                    ],
                ]);
            }

            $receipt = $this->receiptService->confirm(
                tag: $data['tag'] ?? null,
                machineId: (int) $data['machine_id'],
                userId: (int) $request->user()->id,
                notes: $data['notes'] ?? null,
                materialIssueItemId: isset($data['material_issue_item_id']) ? (int) $data['material_issue_item_id'] : null,
            );

            $receipt->loadMissing(['part:id,part_number,part_name,model,size', 'receiver:id,name']);

            return response()->json([
                'ok' => true,
                'message' => __('Material diterima di mesin.'),
                'data' => [
                    'id' => $receipt->id,
                    'material_issue_item_id' => $receipt->material_issue_item_id,
                    'tag' => $receipt->tag,
                    'part_id' => $receipt->part_id,
                    'part' => $receipt->part ? [
                        'part_number' => $receipt->part->part_number,
                        'part_name' => $receipt->part->part_name,
                        'model' => $receipt->part->model,
                        'size' => $receipt->part->size,
                    ] : null,
                    'machine_id' => $receipt->machine_id,
                    'received_by' => $receipt->received_by,
                    'received_at' => $receipt->received_at?->toIso8601String(),
                    'notes' => $receipt->notes,
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function resolve(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('stock.issue') ?? false, 403, __('Tidak berwenang mengakses data stok.'));
        $data = $request->validate([
            'tag' => ['required', 'string', 'max:255'],
            'part_id' => ['nullable', 'integer', 'exists:parts,id'],
        ]);

        return response()->json(['ok' => true, 'data' => $this->receiptService->resolve(
            $data['tag'], isset($data['part_id']) ? (int) $data['part_id'] : null,
        )]);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()?->hasPermission('stock.issue') ?? false,
            403,
            __('Tidak berwenang mengakses data stok.'),
        );

        $data = $request->validate([
            'machine_id' => ['required', 'integer', 'exists:machines,id'],
            'date' => ['nullable', 'date'],
        ]);

        $receipts = $this->receiptService->receiptsByMachine(
            machineId: (int) $data['machine_id'],
            date: $data['date'] ?? null,
        );

        return response()->json([
            'ok' => true,
            'data' => array_map(fn ($receipt) => [
                'id' => $receipt->id,
                'tag' => $receipt->tag,
                'part_id' => $receipt->part_id,
                'part' => $receipt->part ? [
                    'part_number' => $receipt->part->part_number,
                    'part_name' => $receipt->part->part_name,
                    'model' => $receipt->part->model,
                    'size' => $receipt->part->size,
                ] : null,
                'machine_id' => $receipt->machine_id,
                'received_by' => $receipt->received_by,
                'receiver_name' => $receipt->receiver?->name,
                'received_at' => $receipt->received_at?->toIso8601String(),
                'notes' => $receipt->notes,
            ], $receipts),
        ]);
    }
}
