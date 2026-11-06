# Task 01: Database Schema & Pembersihan Data Lama

**Source:** `docs/prd/unified-po-arrival-iqc.md` — Technical Decisions, Section 2

**What to build:** Menambahkan kolom `iqc_status` (string, default 'pending'), `iqc_passed_at` (nullable timestamp), `iqc_passed_by` (nullable foreignId ke users), dan `delivery_note_no` (nullable string) pada tabel `incoming_arrivals`, memperbarui model `IncomingArrival`, dan mereset data `is_local` lama sesuai keputusan Q4=B.

## Acceptance criteria

- [ ] Migrasi berhasil menambahkan kolom `iqc_status`, `iqc_passed_at`, `iqc_passed_by`, dan `delivery_note_no` pada tabel `incoming_arrivals`.
- [ ] Kolom `is_local` lama dibersihkan/direset agar siap menampung tipe kedatangan baru.
- [ ] Model `IncomingArrival` memperbarui `$fillable`, `$casts`, dan relasi `iqcInspector` (`belongsTo(User::class, 'iqc_passed_by')`).

## Verification

- [ ] Tests pass: `./vendor/bin/pest tests/Feature/IncomingPoArrivalTest.php`
- [ ] Build succeeds: `php artisan migrate --pretend`
- [ ] Output matches: Schema tabel `incoming_arrivals` memuat kolom IQC dan delivery_note_no

## Blocked by

None — can start immediately

## Files likely touched

- `database/migrations/2026_11_06_080000_add_iqc_fields_to_incoming_arrivals.php`
- `app/Models/IncomingArrival.php`

## Estimated scope

S — 2 files

## Rollback

`php artisan migrate:rollback --step=1`
