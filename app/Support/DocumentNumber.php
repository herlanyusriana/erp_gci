<?php

namespace App\Support;

use Illuminate\Database\QueryException;

/**
 * Menjalankan satu unit kerja pembuatan dokumen, lalu mengulanginya bila nomor
 * dokumen yang dihasilkan bentrok dengan request lain.
 *
 * Generator nomor (`generate*No`) menghitung nomor berikutnya dengan `max + 1`
 * tanpa lock, jadi dua request bersamaan bisa menghasilkan nomor yang sama.
 * Kolom nomor punya unique index sehingga INSERT kedua gagal dengan SQLSTATE
 * 23505. Retry harus membungkus INSERT, bukan generator: tabrakannya baru
 * terjadi saat INSERT, dan transaksi Postgres yang gagal sudah abort sehingga
 * nomor harus dibuat ulang di percobaan berikutnya.
 */
final class DocumentNumber
{
    /**
     * Ulangi callback saat INSERT bentrok pada unique index nomor dokumen.
     *
     * Constraint disebut eksplisit supaya pelanggaran unique lain (mis.
     * `po_no` atau `idempotency_key`) tidak ikut diulang: mengulangnya tidak
     * akan pernah berhasil dan hanya menunda pesan error ke pengguna.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function retry(callable $callback, string $constraint, int $attempts = 3): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $callback();
            } catch (QueryException $exception) {
                if ($attempt >= $attempts || ! self::isUniqueViolation($exception, $constraint)) {
                    throw $exception;
                }
            }
        }
    }

    private static function isUniqueViolation(QueryException $exception, string $constraint): bool
    {
        return (string) $exception->getCode() === '23505'
            && str_contains($exception->getMessage(), $constraint);
    }
}
