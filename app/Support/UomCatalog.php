<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ConfigMaster;
use App\Models\Uom as UomModel;
use Throwable;

/**
 * Sumber tunggal kode satuan (UOM) untuk seluruh modul.
 *
 * Master resmi ada di tabel `uoms`, tetapi banyak tabel transaksi menyimpan
 * UOM sebagai string bebas (uom_rm, unit_goods, qty_unit, uom). Helper ini
 * menyatukan normalisasi, validasi, dan default agar tidak ada lagi daftar
 * satuan yang ditulis ulang di controller/form.
 */
final class UomCatalog
{
    /** Satuan berat (basis KGM) yang dipakai untuk posting stok. */
    public const WEIGHT = 'KGM';

    /** Satuan default bila master/config tidak tersedia. */
    public const PIECE = 'PCS';

    /** Satuan kemasan default untuk receive. */
    public const PALLET = 'PALLET';

    /**
     * Satuan kemasan (bundle) yang sah saat receive.
     *
     * @var list<string>
     */
    public const PACKING_UNITS = ['PALLET', 'BUNDLE', 'BOX', 'BAG', 'ROLL', 'PACKAGES'];

    /**
     * Kode cadangan bila tabel `uoms` kosong/belum termigrasi, agar form dan
     * validasi tetap berjalan saat instalasi baru.
     *
     * @var list<string>
     */
    public const FALLBACK_CODES = ['KGM', 'PCS', 'ROLL', 'SHEET', 'COIL', 'SET', 'EA', 'UOM', 'KG', 'PALLET'];

    /** @var list<string>|null */
    private static ?array $codes = null;

    /**
     * Semua kode satuan aktif (huruf besar), dari master `uoms`.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        if (self::$codes !== null) {
            return self::$codes;
        }

        try {
            $codes = UomModel::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->pluck('code')
                ->map(fn ($code): string => self::normalize((string) $code) ?? '')
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (Throwable) {
            $codes = [];
        }

        if ($codes === []) {
            $codes = self::FALLBACK_CODES;
        }

        return self::$codes = array_values($codes);
    }

    /**
     * Validasi kode satuan terhadap master (case-insensitive).
     */
    public static function exists(?string $code): bool
    {
        $normalized = self::normalize($code);

        return $normalized !== null && in_array($normalized, self::codes(), true);
    }

    /**
     * Normalisasi kode satuan: trim + uppercase. Null/blank menjadi null.
     */
    public static function normalize(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $trimmed = strtoupper(trim($code));

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Kode satuan default untuk Part (config `PART.default_uom`), fallback PCS.
     */
    public static function defaultCode(): string
    {
        try {
            $configured = ConfigMaster::getValue('PART', 'default_uom');
        } catch (Throwable) {
            $configured = null;
        }

        $normalized = self::normalize(is_string($configured) ? $configured : null);

        return $normalized ?? self::PIECE;
    }

    /**
     * Apakah kode ini satuan berat (KGM/KG)?
     */
    public static function isWeight(?string $code): bool
    {
        return in_array(self::normalize($code), ['KGM', 'KG'], true);
    }

    /**
     * Satuan kemasan yang sah untuk unit_bundle.
     *
     * @return list<string>
     */
    public static function packingUnits(): array
    {
        return self::PACKING_UNITS;
    }

    /**
     * Bersihkan cache in-memory (dipakai setelah master diubah / di test).
     */
    public static function flush(): void
    {
        self::$codes = null;
    }
}
