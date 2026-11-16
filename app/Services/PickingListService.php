<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\PickingList;
use App\Models\PickingListItem;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Picking list harian: rencana ambil barang jadi per customer tanpa tag.
 *
 * Stok diperiksa saat rencana disimpan (snapshot), lalu satu Delivery per
 * customer dibuat dari rencana yang sama. Tag FG baru ditentukan operator
 * saat memindai barang di APK.
 */
class PickingListService
{
    /**
     * @param  array{picking_date: string, notes?: string|null, items: array<int, array<string, mixed>>}  $data
     */
    public function create(array $data, ?int $userId = null): PickingList
    {
        return DB::transaction(function () use ($data, $userId): PickingList {
            $list = PickingList::create([
                'picking_no' => PickingList::generatePickingNo(now()),
                'picking_date' => $data['picking_date'],
                'status' => PickingList::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $this->syncItems($list, $data['items'] ?? []);

            return $list;
        });
    }

    /**
     * @param  array{picking_date: string, notes?: string|null, items: array<int, array<string, mixed>>}  $data
     */
    public function update(PickingList $list, array $data, ?int $userId = null): PickingList
    {
        if ($list->deliveries()->exists()) {
            throw ValidationException::withMessages([
                'items' => __('Picking list yang sudah menghasilkan pengiriman tidak dapat diubah.'),
            ]);
        }

        return DB::transaction(function () use ($list, $data, $userId): PickingList {
            $list->update([
                'picking_date' => $data['picking_date'],
                'notes' => $data['notes'] ?? null,
                'updated_by' => $userId,
            ]);

            $this->syncItems($list, $data['items'] ?? []);

            return $list;
        });
    }

    /**
     * Tulis ulang baris rencana sekaligus menghitung snapshot stok.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function syncItems(PickingList $list, array $rows): void
    {
        $rows = array_values(array_filter($rows, fn ($row) => isset($row['part_id'], $row['customer_id'])));

        if ($rows === []) {
            throw ValidationException::withMessages([
                'items' => __('Tambahkan minimal satu baris picking.'),
            ]);
        }

        $list->items()->delete();

        // Alokasi dalam satu picking list ikut mengurangi stok tersedia agar
        // dua baris untuk part yang sama tidak sama-sama dinyatakan cukup.
        $allocated = [];

        foreach ($rows as $row) {
            $partId = (int) $row['part_id'];
            $qty = round((float) $row['qty'], 4);
            $check = $this->stockCheck($partId, $qty, $allocated[$partId] ?? 0.0);
            $allocated[$partId] = ($allocated[$partId] ?? 0.0) + $qty;

            $list->items()->create([
                'sequence' => (int) ($row['sequence'] ?? 1),
                'customer_id' => (int) $row['customer_id'],
                'part_id' => $partId,
                'qty' => $qty,
                'qty_unit' => $row['qty_unit'] ?? null,
                'stock_available' => $check['available'],
                'stock_shortage' => $check['shortage'],
                'stock_status' => $check['status'],
                'notes' => $row['notes'] ?? null,
            ]);
        }
    }

    /**
     * Cek stok satu baris: tersedia, kurang berapa, dan apakah kurangnya
     * masih bisa ditutup produksi yang sedang berjalan.
     *
     * @return array{available: float, shortage: float, status: string}
     */
    public function stockCheck(int $partId, float $qty, float $allocated = 0.0): array
    {
        // Satuan stok FG mengikuti satuan part, jadi total per part aman
        // dijumlahkan tanpa filter UOM.
        $stock = PartStock::query()
            ->where('part_id', $partId)
            ->where('qty', '>', 0)
            ->sum('qty');

        $available = round(max(0.0, (float) $stock - $allocated), 4);

        if ($qty <= $available) {
            return ['available' => $available, 'shortage' => 0.0, 'status' => PickingListItem::STOCK_OK];
        }

        $shortage = round($qty - $available, 4);

        if (! PartStock::query()->where('part_id', $partId)->exists()) {
            return [
                'available' => $available,
                'shortage' => $shortage,
                'status' => PickingListItem::STOCK_NO_DATA,
            ];
        }

        return [
            'available' => $available,
            'shortage' => $shortage,
            'status' => $this->hasActiveProduction($partId)
                ? PickingListItem::STOCK_SHORT_IN_PRODUCTION
                : PickingListItem::STOCK_SHORT_NO_PRODUCTION,
        ];
    }

    /** Apakah part ini masih punya WO yang berjalan (bisa menutup kekurangan). */
    public function hasActiveProduction(int $partId): bool
    {
        return WorkOrder::query()
            ->where('part_id', $partId)
            ->whereIn('status', ['planned', 'in_progress'])
            ->exists();
    }

    /**
     * Buat satu Delivery (draft) per customer dari baris picking.
     *
     * @return Collection<int, Delivery>
     */
    public function generateDeliveries(PickingList $list, ?int $userId = null): Collection
    {
        $list->loadMissing('items.part.uom');

        if ($list->items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => __('Picking list belum punya baris untuk dikirim.'),
            ]);
        }

        $existing = Delivery::query()->where('picking_list_id', $list->id)->get();
        if ($existing->isNotEmpty()) {
            return $existing;
        }

        return DB::transaction(function () use ($list, $userId): Collection {
            $deliveries = new Collection;

            foreach ($list->items->groupBy('customer_id') as $customerId => $items) {
                $customer = Customer::query()->find((int) $customerId);

                if ($customer === null) {
                    continue;
                }

                $delivery = Delivery::create([
                    'delivery_no' => Delivery::generateDeliveryNo($list->picking_date),
                    'picking_list_id' => $list->id,
                    'customer_id' => $customer->id,
                    'delivery_date' => $list->picking_date,
                    'status' => Delivery::STATUS_DRAFT,
                    'notes' => $list->notes,
                    'created_by' => $userId,
                ]);

                // Harga jual diambil dari master per customer sebagai snapshot.
                $prices = CustomerPrice::query()
                    ->where('customer_id', $customer->id)
                    ->whereIn('part_id', $items->pluck('part_id')->unique())
                    ->where('is_active', true)
                    ->pluck('price', 'part_id');

                foreach ($items->sortBy('sequence') as $item) {
                    $price = $prices[$item->part_id] ?? null;
                    $qty = (float) $item->qty;

                    DeliveryItem::create([
                        'delivery_id' => $delivery->id,
                        'part_stock_id' => null,
                        'part_id' => $item->part_id,
                        'tag' => null,
                        'qty' => $item->qty,
                        'qty_unit' => $item->qty_unit ?? $item->part?->uom?->code,
                        'sequence' => $item->sequence,
                        'is_picked' => false,
                        'price' => $price !== null ? (float) $price : null,
                        'total_price' => $price !== null ? round($qty * (float) $price, 4) : null,
                        'notes' => $item->notes,
                    ]);
                }

                $deliveries->push($delivery);
            }

            $list->update(['status' => PickingList::STATUS_PICKED, 'updated_by' => $userId]);

            return $deliveries;
        });
    }

    /**
     * Part FG aktif untuk dropdown baris picking.
     *
     * @return array<int, array{id: int, part_number: string, part_name: string, model: string|null, uom: string|null, stock: float}>
     */
    public function selectableParts(): array
    {
        $parts = Part::query()
            ->whereHas('partType', fn ($q) => $q->whereRaw('UPPER(code) = ?', ['FG']))
            ->where('is_active', true)
            ->with(['uom:id,code'])
            ->orderBy('part_number')
            ->get(['id', 'part_number', 'part_name', 'model', 'uom_id']);

        $stocks = PartStock::query()
            ->whereIn('part_id', $parts->pluck('id'))
            ->where('qty', '>', 0)
            ->selectRaw('part_id, SUM(qty) AS total')
            ->groupBy('part_id')
            ->pluck('total', 'part_id');

        return $parts
            ->map(fn (Part $part): array => [
                'id' => (int) $part->id,
                'part_number' => (string) $part->part_number,
                'part_name' => (string) $part->part_name,
                'model' => $part->model,
                'uom' => $part->uom?->code,
                'stock' => round((float) ($stocks[$part->id] ?? 0), 4),
            ])
            ->all();
    }
}
