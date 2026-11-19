# Task 05: Idempotensi `production_results` di server

**Source:** `tasks/audit-fixes/mobile-audit.md` — C3

**What to build:** Laporan hasil produksi bisa dikirim ulang dengan aman: satu
`idempotency_key` menghasilkan tepat satu baris, walau request pertama timeout
setelah server commit.

## Latar

`reportResultRaw` tidak mengirim key dan `storeResult` tidak memvalidasinya.
Tidak ada unique index pada `(work_order, parent_part, result_date, shift,
period_start)`. Retry setelah `receiveTimeout` 30 detik membuat baris kedua,
output dobel, dan `part_stocks` terpotong dua kali.

## Acceptance criteria

- [ ] Kolom `production_results.idempotency_key` (string 80, nullable, **unique**)
      ditambahkan lewat migration aditif.
- [ ] `ProductionResultService::report()` menerima `idempotency_key`; bila key
      sudah ada, **mengembalikan baris yang sama** tanpa membuat baris baru.
- [ ] `storeResult` memvalidasi `idempotency_key` sebagai `nullable|string|max:80`
      — **opsional**, supaya form web yang belum mengirim key tetap jalan
      (Postgres mengizinkan banyak NULL pada unique index).
- [ ] `idempotency_key` masuk `$fillable` model `ProductionResult`.
- [ ] Test: dua POST dengan key sama → satu baris `production_results`, respons
      kedua tetap `ok`; dua POST dengan key berbeda → dua baris.

## Verification

- [ ] Tests pass: `vendor/bin/pest tests/Feature/ProductionResultTest.php tests/Feature/Api/MaterialIssueApiTest.php`
- [ ] Migration: `php artisan migrate` lalu `php artisan migrate:rollback` bersih
- [ ] Lint: `vendor/bin/pint --dirty --format agent`
- [ ] Manual check: `php artisan tinker --execute 'echo \App\Models\ProductionResult::count();'` sebelum/sesudah test retry

## Blocked by

None — can start immediately.

## Files likely touched

- `database/migrations/*_add_idempotency_key_to_production_results.php`
- `app/Services/ProductionResultService.php`
- `app/Http/Controllers/Api/MaterialIssueApiController.php`
- `app/Models/ProductionResult.php`
- `tests/Feature/ProductionResultTest.php`

## Estimated scope

M — 5 file

## Rollback

`php artisan migrate:rollback` menghapus kolom. Baris lama tidak terpengaruh
karena kolomnya nullable; dedupe hanya aktif bila key dikirim.

## Notes

- Pola yang ditiru: `material_issues.idempotency_key` +
  `WoService` baris 533-535 (`first()` sebelum insert).
- Bila key ada tetapi payload berbeda (mis. qty berubah), **kembalikan baris
  lama** dan jangan mengubahnya. Payload yang berbeda dengan key sama adalah bug
  klien; menimpanya justru menghapus jejak.
