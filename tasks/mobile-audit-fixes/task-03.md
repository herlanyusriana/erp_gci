# Task 03: Endpoint pass/reject OQC di API

**Source:** `tasks/audit-fixes/mobile-audit.md` — C2

**What to build:** API mobile punya jalur untuk **menyelesaikan** OQC, bukan
hanya menandai per troli, sehingga dokumen bisa berpindah dari `picked` ke
`qc_passed` tanpa membuka web.

## Latar

`routes/api.php` hanya punya `POST /deliveries/{delivery}/trolley-qc`. Endpoint
pass/reject hanya ada di `routes/web.php` (`deliveries.qc.pass`,
`deliveries.qc.reject`). `DeliveryService::submitOutgoing` menolak selama
`!isQcPassed()`, jadi truk tidak bisa dikirim dari APK.

## Acceptance criteria

- [ ] `POST /api/deliveries/{delivery}/pass-qc` dan `.../reject-qc` tersedia,
      memakai `Gate::authorize('oqc', $delivery)` seperti web.
- [ ] Keduanya memanggil `DeliveryService::passQc()` / `rejectQc()` yang sama —
      tidak ada logika OQC kedua.
- [ ] `qc_notes` opsional untuk pass, **wajib** untuk reject (maks 500),
      mengikuti validasi web.
- [ ] Respons JSON memuat `ok`, `message`, dan `data.status` / `data.qc_status`
      terbaru.
- [ ] Dokumen yang belum `picked` menghasilkan 422 dengan pesan dari service
      (bukan 500).
- [ ] Test: role `qc` berhasil pass/reject; role tanpa `delivery.oqc` → 403;
      reject tanpa catatan → 422; setelah pass, `qc_status = passed`.

## Verification

- [ ] Tests pass: `vendor/bin/pest tests/Feature/Api/DeliveryQcApiTest.php`
- [ ] No regressions: `vendor/bin/pest tests/Feature/DeliveryTest.php`
- [ ] Lint: `vendor/bin/pint --dirty --format agent`
- [ ] Manual check: `php artisan route:list --path=api --method=POST` memuat
      `pass-qc` dan `reject-qc`

## Blocked by

None — can start immediately.

## Files likely touched

- `routes/api.php`
- `app/Http/Controllers/Api/DeliveryApiController.php`
- `tests/Feature/Api/DeliveryQcApiTest.php` (baru)

## Estimated scope

S — 3 file

## Rollback

Hapus dua route dan dua method controller. Tidak ada perubahan data atau skema;
endpoint web tetap berfungsi seperti sebelumnya.

## Notes

- Permission yang dipakai adalah policy `oqc` (sudah ada di `DeliveryPolicy`),
  bukan middleware `permission:delivery.oqc`. Jangan menduplikasi aturan.
- Jangan menambah `pass-qc` ke grup middleware lain tanpa `Gate` — dua lapis
  otorisasi untuk aksi yang sama membuat perilaku sulit diprediksi.
