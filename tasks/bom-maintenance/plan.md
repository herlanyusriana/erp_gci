# Implementation Plan: BOM Maintenance

## Overview

Breakdown of `docs/prd/bom-maintenance.md` into three tasks. Modul ini menambah
alur create dan edit BOM lengkap untuk part FG di web ERP, yang saat ini hanya
memiliki daftar dan detail.

Target task list: file di direktori ini (`tasks/bom-maintenance/`).

## Architecture Decisions

- **Satu BOM per part FG, bukan versi berversi.** `boms.part_id` sudah UNIQUE di
  skema. Edit memperbarui record yang sama, sesuai keputusan wawancara; kolom
  `version` tetap ada tetapi tidak dipakai untuk berversi.
- **BOM baru langsung aktif.** Approval sengaja ditunda ke pengembangan
  berikutnya, sesuai keputusan wawancara.
- **Part dibuat di Master Part, bukan di dalam form BOM.** Form hanya memilih
  part terdaftar agar tidak ada dua jalur pembuatan master.
- **Item diganti sebagai satu kesatuan dalam transaksi.** Menghindari BOM
  setengah jadi bila validasi gagal di tengah.
- **Nomor BOM memakai pola generator repo** dengan `withTrashed()` agar nomor
  tidak terpakai ulang setelah soft delete.

## Phases

### Phase 1: Server

- [ ] Task 01: Membuat BOM lengkap untuk part FG
- [ ] Task 02: Mengedit BOM yang sudah ada

**Checkpoint:** BOM dapat dibuat dan diperbarui lewat endpoint, tetap satu record
per part, dan lolos validasi part FG.

### Phase 2: Antarmuka

- [ ] Task 03: Form tambah dan edit BOM di web

**Final checkpoint:** setiap Success Criterion pada PRD diverifikasi.

## Parallelization

| Task | Category | Notes |
|---|---|---|
| 01 | Must be sequential | Menentukan route, generator nomor, dan bentuk payload item |
| 02 | Must be sequential | Memakai route dan validasi yang sama dengan Task 01 |
| 03 | Needs coordination | Mengonsumsi kontrak payload dari Task 01 dan 02 |

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Aturan field wajib per item masih Open Question di PRD | Med | Validasi minimal (child part + jumlah) dan ditandai eksplisit agar mudah diubah |
| Mengedit BOM yang sudah dipakai Work Order | High | Cek dependensi sebelum menyimpan penghapusan item; angkat temuan bila ada |
| Item BOM sangat banyak membuat form berat | Low | Form berbasis baris sederhana; evaluasi bila data nyata melebihi ratusan baris |
| Nomor BOM bentrok setelah penghapusan | Med | Generator memakai `withTrashed()` dan dikunci test |

## Open Questions

- **Field wajib per item BOM** belum diputuskan pemilik produk. Memblokir
  finalisasi validasi pada Task 01, tidak memblokir mulai kerja. Rekomendasi
  breakdown: child part, jumlah, dan UOM wajib; sequence wajib untuk baris
  proses; proses/mesin dan parent WIP wajib hanya untuk langkah produksi.

## Task Index

| # | Task | Size | Blocked by | Category | File |
|---|---|---|---|---|---|
| 01 | Membuat BOM lengkap untuk part FG | M | — | sequential | `task-01.md` |
| 02 | Mengedit BOM yang sudah ada | S | 01 | sequential | `task-02.md` |
| 03 | Form tambah dan edit BOM di web | M | 02 | coordination | `task-03.md` |
