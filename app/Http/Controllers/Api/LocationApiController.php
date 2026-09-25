<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Resolusi QR lokasi rak untuk APK Material Tracker.
 *
 * Bentuk respons mengikuti `machines/resolve` supaya APK memakai pola parse
 * yang sama. Lokasi nonaktif diperlakukan sebagai tidak ditemukan.
 */
class LocationApiController extends Controller
{
    public function resolve(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()?->hasPermission('stock.issue') ?? false,
            403,
            __('Tidak berwenang mengakses data stok.'),
        );

        $data = $request->validate([
            'location_code' => ['nullable', 'string', 'max:40'],
            'location_id' => ['nullable', 'integer'],
        ]);

        $location = Location::query()
            ->where('is_active', true)
            ->when($data['location_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when(
                ! ($data['location_id'] ?? null) && ($data['location_code'] ?? null),
                fn ($q) => $q->whereRaw('LOWER(COALESCE(code, \'\')) = ?', [mb_strtolower(trim((string) $data['location_code']))]),
            )
            ->first();

        if ($location === null) {
            return response()->json([
                'ok' => false,
                'message' => __('Lokasi tidak ditemukan.'),
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'id' => (int) $location->id,
                'location_code' => $location->code,
                'location_name' => $location->name,
            ],
        ]);
    }
}
