# Task 03: Menu & Ceklis Incoming Quality (IQC Pass)

**Source:** `docs/prd/unified-po-arrival-iqc.md` — Technical Decisions, Section 1 & 2

**What to build:** Membangun modul baru **Incoming Quality** (`/incoming-quality`) dengan controller `IncomingQualityController` dan view `resources/js/Pages/Quality/Incoming/Index.vue`. Halaman ini menyajikan tabel ringkas sesuai arahan Pak Arnold: `Tanggal Arrival | Supp Name | PO No | Item List | Ceklis IQC Pass`.

## Acceptance criteria

- [x] Route `GET /incoming-quality` dan `POST /incoming-quality/{arrival}/pass` terdaftar dan dilindungi otorisasi QC (`qc.view`, `qc.create`, atau role `qc`).
- [x] Tabel menampilkan daftar kedatangan dengan kolom: Tanggal Arrival, Supp Name, PO No, Item List (ringkasan part & qty), dan tombol/ceklis `IQC Pass`.
- [x] Menekan tombol ceklis `IQC Pass` mengupdate `iqc_status = 'passed'`, mencatat `iqc_passed_at = now()`, dan `iqc_passed_by = auth()->id()`.
- [x] Menyediakan opsi batal/undo IQC Pass jika salah klik.
- [x] Menambahkan tile `Incoming Quality` di `resources/js/Pages/Quality/ModuleLauncher.vue` (jika ada) atau launcher terkait.

## Verification

- [x] Tests pass: `./vendor/bin/pest tests/Feature/IncomingQualityTest.php`
- [x] Build succeeds: `npm run build`
- [ ] Manual check: Klik tombol IQC Pass langsung mengubah status baris menjadi Lolos / Pass

## Blocked by

Task 01 — Database Schema & Pembersihan Data Lama

## Files likely touched

- `app/Http/Controllers/IncomingQualityController.php`
- `resources/js/Pages/Quality/Incoming/Index.vue`
- `routes/web.php`
- `resources/js/i18n/catalogs/quality.ts`

## Estimated scope

M — 4 files

## Rollback

Hapus `IncomingQualityController` dan route terkait.
