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
            'tag' => ['nullable', 'required_without:material_issue_item_id', 'string', 'max:255'],
            'material_issue_item_id' => ['nullable', 'integer', 'exists:material_issue_items,id'],
            'machine_id' => ['required', 'integer', 'exists:machines,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
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
