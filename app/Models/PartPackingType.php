<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Katalog packing type yang berlaku untuk satu part.
 *
 * Sumbernya gabungan riwayat item Arrival (`origin = arrival`) dan tambahan
 * manual pengelola (`origin = manual`). Disimpan terpisah dari transaksi agar
 * menambah pilihan tidak menciptakan kedatangan palsu.
 */
class PartPackingType extends Model
{
    public const ORIGIN_ARRIVAL = 'arrival';

    public const ORIGIN_MANUAL = 'manual';

    protected $fillable = ['part_id', 'code', 'origin'];

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    /**
     * Kode packing type untuk satu part, terurut abjad.
     *
     * @return list<string>
     */
    public static function codesFor(int $partId): array
    {
        return static::query()
            ->where('part_id', $partId)
            ->orderBy('code')
            ->pluck('code')
            ->map(fn ($code): string => (string) $code)
            ->values()
            ->all();
    }

    /**
     * Pastikan kode tersedia untuk part tersebut tanpa menggandakan baris.
     */
    public static function register(int $partId, string $code): void
    {
        static::firstOrCreate(
            ['part_id' => $partId, 'code' => strtoupper(trim($code))],
            ['origin' => self::ORIGIN_MANUAL],
        );
    }

    /**
     * Bangun katalog dari packing type yang benar-benar pernah tercatat pada
     * item Arrival per part. Idempoten: aman dijalankan berulang.
     *
     * @return int jumlah kode yang ditambahkan
     */
    public static function syncFromArrivalHistory(): int
    {
        $rows = DB::table('incoming_arrival_items')
            ->whereNotNull('part_id')
            ->whereNotNull('unit_bundle')
            ->where('unit_bundle', '!=', '')
            ->whereNull('deleted_at')
            ->select('part_id', 'unit_bundle')
            ->distinct()
            ->get();

        $now = now();

        $payload = $rows->map(fn ($row) => [
            'part_id' => $row->part_id,
            'code' => strtoupper(trim((string) $row->unit_bundle)),
            'origin' => self::ORIGIN_ARRIVAL,
            'created_at' => $now,
            'updated_at' => $now,
        ])->filter(fn (array $row) => $row['code'] !== '')
            ->unique(fn (array $row) => $row['part_id'].'|'.$row['code'])
            ->values()
            ->all();

        return $payload === [] ? 0 : DB::table('part_packing_types')->insertOrIgnore($payload);
    }
}
