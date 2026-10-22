# Implementation Plan: Unified Incoming Supplier PO

## Overview

Breakdown of `docs/prd/incoming-po-unification.md` into four tasks. Modul ini
menyatukan entry point pencatatan PO supplier untuk source `local` dan `import`,
dengan kategori source pada Master Supplier sebagai penentu filter.

Target task list: file di direktori ini (`tasks/incoming-po-unification/`).

## Architecture Decisions

- **Source berasal dari supplier, bukan dari record PO.** Wawancara menetapkan
  setiap supplier punya tepat satu kategori. Filter PO dan validasi server
  mengikuti kategori itu.
- **Lifecycle tidak disamakan.** Import tetap lanjut ke Arrival terpisah; local
  tetap di Local PO. Yang disatukan hanya menu, daftar, dan entry point form.
- **Klasifikasi data lama bertingkat.** Nama berawalan `PT` → `local`; selain itu
  mengikuti riwayat bila konsisten; ambigu dibiarkan kosong untuk diputuskan
  pengelola. Sistem tidak menebak.
- **Source dikunci setelah simpan.** Perpindahan lifecycle tidak diizinkan lewat
  edit; kesalahan ditangani dengan membatalkan dan mencatat ulang.
- **Aplikasi mencatat PO yang diterima dari supplier**, bukan menerbitkan PO.
- **Kategori supplier baru selalu manual**, aturan nama hanya untuk backfill.

## Phases

### Phase 1: Fondasi source

- [ ] Task 01: Kategori source pada Master Supplier

**Checkpoint:** setiap supplier baru wajib berkategori; data lama terklasifikasi
sesuai aturan yang dapat diperiksa.

### Phase 2: Alur pencatatan

- [ ] Task 02: Form PO dengan source dan supplier terfilter
- [ ] Task 04: Source PO terkunci setelah disimpan

**Checkpoint:** PO dapat dicatat pada alur yang benar dan source tidak dapat
dipindahkan setelah tersimpan.

### Phase 3: Daftar gabungan

- [ ] Task 03: Daftar PO gabungan dengan filter source

**Final checkpoint:** setiap Success Criterion pada PRD diverifikasi.

## Parallelization

| Task | Category | Notes |
|---|---|---|
| 01 | Must be sequential | Menentukan kolom, model, dan backfill yang dibaca task lain |
| 02 | Needs coordination | Memakai kontrak kategori supplier dari Task 01 |
| 03 | Safe to parallelize | Daftar hanya membaca; dapat dikerjakan setelah Task 01 |
| 04 | Must be sequential | Memperketat validasi pada alur simpan dari Task 02 |

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Backfill `PT` salah mengklasifikasi supplier import yang kebetulan berawalan `PT` | Med | Hanya nama berawalan `PT` yang diisi otomatis; hasil backfill ditampilkan agar dapat dikoreksi pengelola |
| Supplier dipakai untuk dua source sehingga kategori tunggal tidak cukup | High | Riwayat campuran sengaja dibiarkan kosong dan diangkat sebagai temuan; keputusan model multi-kategori butuh persetujuan baru |
| Refactor form menghilangkan field/validasi khusus per source | High | Regression test Local PO dan Purchase OrderSupplierPart wajib lulus |
| Kategori supplier kosong memblokir pencatatan PO | Med | Backfill dijalankan lebih dulu; supplier ambigu ditetapkan manual sebelum dipakai |

## Open Questions

Tidak ada keputusan produk yang terbuka. Catatan eksekusi: bila ditemukan
supplier dengan riwayat campuran, angkat sebagai temuan sebelum memaksakan satu
kategori.

## Task Index

| # | Task | Size | Blocked by | Category | File |
|---|---|---|---|---|---|
| 01 | Kategori source pada Master Supplier | M | — | sequential | `task-01.md` |
| 02 | Form PO dengan source dan supplier terfilter | L | 01 | coordination | `task-02.md` |
| 03 | Daftar PO gabungan dengan filter source | S | 01 | parallel | `task-03.md` |
| 04 | Source PO terkunci setelah disimpan | S | 02 | sequential | `task-04.md` |
