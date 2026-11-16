<?php

namespace App\Console\Commands;

use App\Support\TrolleyCsv;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Impor/segarkan master troli dari file Export CSV aplikasi Trolley Scan.
 *
 * Dipakai untuk pembaruan ad-hoc tanpa mengubah snapshot di repo. Untuk data
 * yang ikut ter-deploy, ganti `database/seeders/data/trolleys.csv`.
 */
class ImportTrolleys extends Command
{
    protected $signature = 'trolleys:import {file : Path ke file CSV hasil Export}';

    protected $description = 'Impor master troli dari CSV Export aplikasi Trolley Scan';

    public function handle(): int
    {
        try {
            $path = (string) $this->argument('file');
            $parsed = TrolleyCsv::parse($path);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $result = TrolleyCsv::store($parsed['rows'], $parsed['skipped']);

        $this->info(sprintf(
            'Master troli diimpor: %d baru, %d diperbarui, %d dilewati (total %d baris).',
            $result['created'],
            $result['updated'],
            $result['skipped'],
            $result['created'] + $result['updated'] + $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
