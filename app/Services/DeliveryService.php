<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\Location;
use App\Models\Part;
use App\Models\PartStock;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Pengiriman barang jadi: saran picking FIFO, validasi tag, dan pemindahan
 * lokasi ke area pengiriman.
 *
 * Stok total tidak berubah saat pemindahan; yang berubah hanya lokasi fisik
 * barang. Barang baru benar-benar keluar dari pabrik saat dokumen dikirim.
 */
class DeliveryService
{
    /**
     * Tag FG yang tersedia untuk dikirim, urut FIFO (tertua dulu).
     *
     * @return list<array<string, mixed>>
     */
    public function availableTags(?int $partId = null, ?string $search = null, int $limit = 50): array
    {
        $prepCode = $this->deliveryPrepLocationCode();

        return PartStock::query()
            ->with(['part:id,part_number,part_name,part_type_id', 'part.partType:id,code'])
            ->where('qty', '>', 0)
            ->whereNotNull('tag')
            ->when($partId !== null, fn ($q) => $q->where('part_id', $partId))
            ->when($search !== null && $search !== '', fn ($q) => $q
                ->where(fn ($w) => $w
                    ->where('tag', 'ilike', "%{$search}%")
                    ->orWhereHas('part', fn ($p) => $p
                        ->where('part_number', 'ilike', "%{$search}%")
                        ->orWhere('part_name', 'ilike', "%{$search}%"))))
            ->orderByRaw('received_at ASC NULLS LAST')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->filter(fn (PartStock $stock): bool => $this->isFinishedGood($stock))
            ->map(fn (PartStock $stock): array => [
                'part_stock_id' => (int) $stock->id,
                'part_id' => (int) $stock->part_id,
                'part_number' => $stock->part?->part_number,
                'part_name' => $stock->part?->part_name,
                'tag' => (string) $stock->tag,
                'qty' => round((float) $stock->qty, 4),
                'qty_unit' => $stock->qty_unit,
                'location_code' => $stock->location_code,
                'received_at' => $stock->received_at?->toIso8601String(),
                'in_prep' => $prepCode !== null && $stock->location_code === $prepCode,
            ])
            ->values()
            ->all();
    }

    /**
     * Tag yang sah untuk satu baris pengiriman: harus barang jadi dan berstok.
     */
    public function assertDeliverable(PartStock $stock, float $qty): void
    {
        if (! $this->isFinishedGood($stock)) {
            throw ValidationException::withMessages([
                'items' => __('Tag :tag bukan barang jadi.', ['tag' => $stock->tag ?? '-']),
            ]);
        }

        if ((float) $stock->qty + 1e-9 < $qty) {
            throw ValidationException::withMessages([
                'items' => __('Qty :qty melebihi stok tag :tag (:available).', [
                    'qty' => $qty,
                    'tag' => $stock->tag ?? '-',
                    'available' => (float) $stock->qty,
                ]),
            ]);
        }
    }

    /**
     * Pindahkan seluruh tag pada dokumen ke area pengiriman dan tandai picked.
     */
    public function moveToPreparation(Delivery $delivery): void
    {
        $prepCode = $this->deliveryPrepLocationCode();

        if ($prepCode === null) {
            throw ValidationException::withMessages([
                'status' => __('Lokasi Delivery Preparation Area belum diatur di master lokasi.'),
            ]);
        }

        if ($delivery->isShipped()) {
            throw ValidationException::withMessages([
                'status' => __('Pengiriman yang sudah dikirim tidak dapat dipindah.'),
            ]);
        }

        $delivery->loadMissing('items');

        if ($delivery->items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => __('Tambahkan minimal satu barang sebelum memindahkan.'),
            ]);
        }

        foreach ($delivery->items as $item) {
            $stock = PartStock::query()->lockForUpdate()->find($item->part_stock_id);

            if ($stock === null) {
                throw ValidationException::withMessages([
                    'items' => __('Stok tag :tag tidak ditemukan.', ['tag' => $item->tag ?? '-']),
                ]);
            }

            // Simpan lokasi asal SEBELUM dipindah agar pengembalian presisi.
            if ($item->origin_location_code === null) {
                $item->update(['origin_location_code' => $stock->location_code]);
            }

            $stock->update(['location_code' => $prepCode]);
        }

        $delivery->update([
            'status' => Delivery::STATUS_PICKED,
            'picked_at' => now(),
        ]);
    }

    /**
     * Kembalikan tag ke lokasi asal dan turunkan status ke draft.
     */
    public function returnToOrigin(Delivery $delivery): void
    {
        if ($delivery->isShipped()) {
            throw ValidationException::withMessages([
                'status' => __('Pengiriman yang sudah dikirim tidak dapat dikembalikan.'),
            ]);
        }

        $delivery->loadMissing('items');

        foreach ($delivery->items as $item) {
            if ($item->origin_location_code === null) {
                continue;
            }

            PartStock::query()
                ->whereKey($item->part_stock_id)
                ->update(['location_code' => $item->origin_location_code]);
        }

        $delivery->update([
            'status' => Delivery::STATUS_DRAFT,
            'picked_at' => null,
        ]);
    }

    /** Kode lokasi bertipe area pengiriman. */
    public function deliveryPrepLocationCode(): ?string
    {
        return Location::query()
            ->where('type', Location::TYPE_DELIVERY_PREP)
            ->where('is_active', true)
            ->orderBy('code')
            ->value('code');
    }

    /**
     * Saran FIFO per part: tag tertua untuk tiap part yang diminta.
     *
     * @param  list<int>  $partIds
     * @return array<int, array<string, mixed>>
     */
    public function suggestFifo(array $partIds): array
    {
        if ($partIds === []) {
            return [];
        }

        return PartStock::query()
            ->with('part:id,part_number,part_name')
            ->whereIn('part_id', $partIds)
            ->where('qty', '>', 0)
            ->whereNotNull('tag')
            ->orderByRaw('received_at ASC NULLS LAST')
            ->orderBy('id')
            ->get()
            ->groupBy('part_id')
            ->map(fn (Collection $group, $partId): array => [
                'part_id' => (int) $partId,
                'part_number' => $group->first()?->part?->part_number,
                'tag' => (string) $group->first()?->tag,
                'part_stock_id' => (int) $group->first()?->id,
                'qty' => round((float) $group->first()?->qty, 4),
                'qty_unit' => $group->first()?->qty_unit,
                'location_code' => $group->first()?->location_code,
            ])
            ->values()
            ->all();
    }

    /** Apakah stok ini barang jadi? */
    public function isFinishedGood(PartStock $stock): bool
    {
        $stock->loadMissing('part.partType');

        return strtoupper((string) $stock->part?->partType?->code) === 'FG';
    }

    /**
     * Part bertipe FG yang masih punya stok, untuk dropdown pilih part.
     *
     * @return list<array<string, mixed>>
     */
    public function deliverableParts(): array
    {
        return Part::query()
            ->whereHas('partType', fn ($q) => $q->whereRaw('UPPER(code) = ?', ['FG']))
            ->whereIn('id', PartStock::query()->where('qty', '>', 0)->select('part_id'))
            ->orderBy('part_number')
            ->get(['id', 'part_number', 'part_name'])
            ->map(fn (Part $part): array => [
                'id' => (int) $part->id,
                'part_number' => $part->part_number,
                'part_name' => $part->part_name,
            ])
            ->all();
    }
}
