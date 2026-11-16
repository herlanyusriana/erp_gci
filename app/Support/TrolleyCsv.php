<?php

namespace App\Support;

use App\Models\Trolley;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Pembaca berkas Export CSV dari aplikasi Trolley Scan.
 *
 * Kolom dicari berdasarkan nama header, jadi urutan kolom boleh berubah.
 * Hanya `Code` yang wajib. Kolom `Status` sengaja diabaikan: status in/out
 * adalah pergerakan di aplikasi sumber, bukan atribut master.
 *
 * Dipakai bersama oleh seeder dan `php artisan trolleys:import`.
 */
final class TrolleyCsv
{
    /**
     * @return array{rows: list<array<string, mixed>>, skipped: int}
     */
    public static function parse(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("File CSV tidak ditemukan atau tidak bisa dibaca: {$path}");
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Gagal membuka file: {$path}");
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw new RuntimeException('File CSV kosong.');
        }

        $index = array_flip(array_map(fn ($h): string => strtolower(trim((string) $h)), $header));

        if (! isset($index['code'])) {
            fclose($handle);
            throw new RuntimeException('Kolom "Code" tidak ditemukan. Pastikan memakai file Export dari aplikasi Trolley Scan.');
        }

        $column = function (array $row, string $key) use ($index): ?string {
            $i = $index[$key] ?? null;
            if ($i === null || ! isset($row[$i])) {
                return null;
            }

            $value = trim((string) $row[$i]);

            return $value === '' ? null : $value;
        };

        $rows = [];
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $code = $column($row, 'code');
            if ($code === null) {
                $skipped++;

                continue;
            }

            $kind = strtolower($column($row, 'kind') ?? '');

            $rows[] = [
                'code' => strtoupper($code),
                'external_id' => ($id = $column($row, 'id')) !== null && ctype_digit($id) ? (int) $id : null,
                'type' => strtolower($column($row, 'type') ?? 'internal'),
                'kind' => $kind === '' ? null : $kind,
                'capacity' => ($cap = $column($row, 'capacity')) !== null && ctype_digit($cap) ? (int) $cap : null,
                'location' => $column($row, 'location'),
                'notes' => $column($row, 'notes'),
            ];
        }

        fclose($handle);

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /**
     * Simpan baris hasil parse. Idempoten berdasarkan `code`.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{created: int, updated: int, skipped: int}
     */
    public static function store(array $rows, int $skipped = 0): array
    {
        $created = 0;
        $updated = 0;
        $now = Carbon::now();

        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');
            if ($code === '') {
                $skipped++;

                continue;
            }

            $trolley = Trolley::query()->firstOrNew(['code' => $code]);
            $exists = $trolley->exists;

            $trolley->fill([
                'external_id' => $row['external_id'] ?? $trolley->external_id,
                'type' => $row['type'] ?? $trolley->type ?? 'internal',
                'kind' => $row['kind'] ?? $trolley->kind,
                'capacity' => $row['capacity'] ?? $trolley->capacity,
                'location' => $row['location'] ?? $trolley->location,
                'notes' => $row['notes'] ?? $trolley->notes,
                'synced_at' => $now,
            ]);

            $trolley->save();

            $exists ? $updated++ : $created++;
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }
}
