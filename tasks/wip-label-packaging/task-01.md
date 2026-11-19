# Task 01: Database & Model Carry-Over

**Source:** `docs/prd/wip-label-packaging.md` — Technical Decisions, Section 2

**What to build:** Menambahkan kolom `label_carried_over_qty` (decimal, default 0) pada tabel `production_results` dan memperbarui model `ProductionResult` untuk mencatat kuantitas label yang ditunda pelabelannya tanpa mengubah nilai kuantitas riil hasil produksi operator.

## Acceptance criteria

- [x] Kolom `label_carried_over_qty` bertipe decimal(12,4) default 0 berhasil ditambahkan ke tabel `production_results`.
- [x] Kolom `label_carry_over_source_id` (nullable foreign id ke `production_results`) ditambahkan untuk menautkan riwayat penggabungan sisa antar periode.
- [x] Model `ProductionResult` menambahkan field baru ke `$fillable` dan `$casts`.
- [x] Menambahkan scope/helper untuk menghitung sisa kuantitas yang siap dicetak pada periode berjalan (termasuk carry-over dari periode sebelumnya).

## Verification

- [x] Tests pass: `./vendor/bin/pest tests/Feature/ProductionLabelQueueTest.php`
- [x] Build succeeds: `php artisan migrate --pretend`
- [x] Output matches: schema table `production_results` memuat kolom carry-over
- [x] No regressions: `./vendor/bin/pest`
- [ ] Manual check: tinker `new ProductionResult(['label_carried_over_qty' => 52])` valid

## Blocked by

None — can start immediately

## Files likely touched

- `database/migrations/2026_11_05_150000_add_label_carry_over_to_production_results.php`
- `app/Models/ProductionResult.php`
- `tests/Feature/ProductionLabelQueueTest.php`

## Estimated scope

S — 2-3 files

## Rollback

`php artisan migrate:rollback --step=1` (menghapus kolom carry-over).
