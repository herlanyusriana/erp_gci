<?php

namespace Database\Seeders;

use App\Models\PartPackingType;
use App\Models\PartSubstitute;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

/**
 * Isi data turunan yang bergantung pada data master dan transaksi.
 *
 * Dijalankan sebagai seeder, bukan bagian migrasi, agar migrasi tetap murni DDL
 * dan tidak bergantung pada model yang bisa berubah.
 */
class DerivedDataSeeder extends Seeder
{
    public function run(): void
    {
        // Katalog packing type dari riwayat Arrival yang sudah ada.
        PartPackingType::syncFromArrivalHistory();

        // Lengkapi packing type relasi substitute yang punya satu nilai historis.
        PartSubstitute::backfillPackingType();

        // Klasifikasi source supplier lama; kategori yang sudah ada tidak diubah.
        Supplier::backfillSource();

        // Supplier yang tersisa tanpa kategori adalah pemasok luar negeri pada
        // data master. Keputusan pemilik produk: seluruhnya import.
        Supplier::query()
            ->whereNull('source')
            ->update(['source' => Supplier::SOURCE_IMPORT]);
    }
}
