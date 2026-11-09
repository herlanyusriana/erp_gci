# Task 01: Database Migration & Model: Multi-Tier Packaging Fields

**Source:** docs/prd/tiered-receive-and-menu-separation.md — Technical Decisions 1

**What to build:**
Membuat migrasi database untuk menambahkan kolom kemasan bertingkat pada tabel `incoming_receives`: `bundle_count` (jumlah wadah besar), `packing_count` (jumlah kemasan dalam per wadah besar), `packing_type` (jenis kemasan dalam), dan `packing_qty` (isi kuantitas barang per kemasan dalam). Memperbarui `$fillable` dan `$casts` pada model `IncomingReceive`.

## Acceptance criteria

- [ ] Migrasi menambahkan kolom nullable `bundle_count` (decimal 20,4 / integer), `packing_count` (decimal 20,4 / integer), `packing_type` (varchar 50), dan `packing_qty` (decimal 20,4) pada `incoming_receives`.
- [ ] Kolom bersifat nullable agar tidak merusak data historis yang sudah ada.
- [ ] Model `IncomingReceive` memperbarui `$fillable` dan `$casts` untuk atribut-atribut baru tersebut.
- [ ] Method `down()` pada migrasi mampu melakukan rollback dengan aman (`dropColumn`).

## Verification

- [ ] Tests pass: `php artisan migrate --pretend`
- [ ] Build succeeds: `php artisan migrate`
- [ ] Output matches: Kolom baru terdaftar pada schema tabel `incoming_receives`.
- [ ] No regressions: `vendor/bin/pest tests/Feature/ReceiveStockUnitTest.php`
- [ ] Manual check: Periksa tabel via tinker / `database-schema`.

## Blocked by

None — can start immediately.

## Files likely touched

- `database/migrations/2026_11_07_000001_add_tiered_packaging_to_incoming_receives_table.php`
- `app/Models/IncomingReceive.php`

## Estimated scope

S — 2 files

## Rollback

Jalankan `php artisan migrate:rollback --step=1`.

## Notes

Gunakan tipe numerik yang presisi (20,4) untuk mengakomodasi kebutuhan kuantitas pecahan jika diperlukan.
