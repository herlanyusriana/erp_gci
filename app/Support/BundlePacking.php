<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Pembagian satu tag material menjadi beberapa bundle (packing) yang isinya sama.
 *
 * Semua bundle dalam satu tag diasumsikan berisi kuantitas yang sama:
 * `packing_qty = floor(total_qty / bundle_count)` pada skala presisi yang dipakai
 * aplikasi (4 desimal). Sisa pembagian tidak dibagikan ke bundle terakhir.
 *
 * Kelas ini murni perhitungan — tanpa akses database — supaya aturan auto-centang
 * bisa diuji langsung.
 */
final class BundlePacking
{
    /** Presisi kuantitas sistem (kolom decimal(20,4)). */
    public const PRECISION = 4;

    /**
     * Isi tiap bundle. 0 bila jumlah bundle tidak valid.
     */
    public static function packingQty(float $totalQty, int $bundleCount): float
    {
        if ($bundleCount < 1 || $totalQty <= 0) {
            return 0.0;
        }

        $scaled = (int) round($totalQty * (10 ** self::PRECISION));

        return round(intdiv($scaled, $bundleCount) / (10 ** self::PRECISION), self::PRECISION);
    }

    /**
     * Daftar bundle berurutan 1..N beserta isinya.
     *
     * @return list<array{bundle_no: int, qty: float}>
     */
    public static function bundles(float $totalQty, int $bundleCount): array
    {
        $packingQty = self::packingQty($totalQty, $bundleCount);

        $bundles = [];
        for ($i = 1; $i <= max(0, $bundleCount); $i++) {
            $bundles[] = ['bundle_no' => $i, 'qty' => $packingQty];
        }

        return $bundles;
    }

    /**
     * Kebutuhan bersih = qty WO − stok/WIP yang sudah ada. Tidak pernah negatif.
     */
    public static function netRequirement(float $woQty, float $stockQty): float
    {
        return round(max(0.0, $woQty - $stockQty), self::PRECISION);
    }

    /**
     * Jumlah bundle yang otomatis dicentang: floor(kebutuhan bersih ÷ isi bundle).
     */
    public static function autoCheckCount(float $netRequirement, float $packingQty, int $bundleCount): int
    {
        if ($packingQty <= 0 || $netRequirement <= 0) {
            return 0;
        }

        $scaled = (int) round($netRequirement * (10 ** self::PRECISION));
        $count = intdiv($scaled, (int) round($packingQty * (10 ** self::PRECISION)));

        return (int) min(max(0, $count), max(0, $bundleCount));
    }

    /**
     * Susun rencana issue satu tag: bundle yang dicentang otomatis + qty manual.
     *
     * @param  float  $manualQty  qty manual dari operator (0 bila tidak ada)
     * @return array{
     *     bundles: list<array{bundle_no: int, qty: float, checked: bool}>,
     *     packing_qty: float,
     *     bundle_count: int,
     *     net_requirement: float,
     *     bundle_qty: float,
     *     manual_qty: float,
     *     total_qty: float,
     *     exceeds: bool
     * }
     */
    public static function plan(
        float $woQty,
        float $stockQty,
        float $totalQty,
        int $bundleCount,
        float $manualQty = 0.0,
    ): array {
        if ($manualQty < 0) {
            throw new InvalidArgumentException('Qty manual tidak boleh negatif.');
        }

        $netRequirement = self::netRequirement($woQty, $stockQty);
        $packingQty = self::packingQty($totalQty, $bundleCount);
        $checked = self::autoCheckCount($netRequirement, $packingQty, $bundleCount);

        $bundles = [];
        $bundleQty = 0.0;
        for ($i = 1; $i <= max(0, $bundleCount); $i++) {
            $isChecked = $i <= $checked;
            if ($isChecked) {
                $bundleQty += $packingQty;
            }

            $bundles[] = [
                'bundle_no' => $i,
                'qty' => $packingQty,
                'checked' => $isChecked,
            ];
        }

        $bundleQty = round($bundleQty, self::PRECISION);
        $totalIssue = round($bundleQty + $manualQty, self::PRECISION);

        return [
            'bundles' => $bundles,
            'packing_qty' => $packingQty,
            'bundle_count' => max(0, $bundleCount),
            'net_requirement' => $netRequirement,
            'bundle_qty' => $bundleQty,
            'manual_qty' => round($manualQty, self::PRECISION),
            'total_qty' => $totalIssue,
            'exceeds' => $totalIssue > $netRequirement + 1e-9,
        ];
    }
}
