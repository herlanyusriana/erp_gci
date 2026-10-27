<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Menjaga pesan validasi tetap menempel pada baris yang benar saat form
 * mengirim ulang baris dinamis yang sudah disaring di klien.
 *
 * Form PO / Local PO menyaring baris kosong sebelum submit, sehingga index
 * yang dilihat pengguna tidak sama dengan index pada payload. Setiap baris
 * membawa `uid` yang stabil, dan pesan `items.<payloadIndex>.<field>` diubah
 * menjadi `items.<uid>.<field>` agar frontend bisa mencocokkannya.
 */
final class RowErrorKeys
{
    /**
     * Ubah key error `items.<n>.<field>` menjadi `items.<uid>.<field>`.
     *
     * Selalu melempar ulang ValidationException agar validasi tidak pernah
     * lolos diam-diam: tanpa uid pun pesan asli harus sampai ke pengguna.
     *
     * @param  array<string, mixed>  $items  payload items yang sudah divalidasi (berurutan)
     *
     * @throws ValidationException
     */
    public static function remap(ValidationException $exception, array $items): never
    {
        $uids = [];

        foreach (array_values($items) as $index => $item) {
            if (is_array($item) && isset($item['uid']) && $item['uid'] !== null && $item['uid'] !== '') {
                $uids[$index] = (string) $item['uid'];
            }
        }

        if ($uids === []) {
            throw $exception;
        }

        $errors = $exception->errors();
        $remapped = [];

        foreach ($errors as $key => $messages) {
            if (preg_match('/^items\.(\d+)\.(.+)$/', $key, $matches) && isset($uids[(int) $matches[1]])) {
                $key = 'items.'.$uids[(int) $matches[1]].'.'.$matches[2];
            }

            $remapped[$key] = $messages;
        }

        throw ValidationException::withMessages($remapped);
    }

    /**
     * Key error per baris, dipakai ulang sebagai aturan validasi `uid`.
     *
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        return ['nullable', 'integer', 'min:1'];
    }
}
