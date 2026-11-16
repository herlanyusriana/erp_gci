<?php

namespace Database\Seeders;

use App\Support\TrolleyCsv;
use Illuminate\Database\Seeder;

/**
 * Master troli dari aplikasi Trolley Scan.
 *
 * Sumbernya `database/seeders/data/trolleys.csv` — file Export CSV dari
 * aplikasi tersebut. Hanya identitas troli yang disimpan; status in/out tidak
 * ikut karena itu pergerakan, bukan master.
 *
 * Idempoten: kode adalah business key, jadi aman dijalankan berulang. Untuk
 * memperbarui data, ganti file CSV-nya lalu jalankan ulang seeder.
 */
class TrolleySeeder extends Seeder
{
    public function run(): void
    {
        $parsed = TrolleyCsv::parse(database_path('seeders/data/trolleys.csv'));

        TrolleyCsv::store($parsed['rows'], $parsed['skipped']);
    }
}
