# Implementation Plan: Material Substitute Attributes

## Overview

Breakdown of `docs/prd/substitute-attributes.md` into three tasks. Modul ini
menambahkan ukuran, UOM, dan packing type pada relasi material substitute per
supplier, dengan packing type bersumber dari riwayat Arrival per part.

Target task list: file di direktori ini (`tasks/substitute-attributes/`). Ini
satu-satunya sumber status.

## Architecture Decisions

- **Packing type milik part, bukan master global.** Wawancara menolak master
  packing type terpisah; pilihan dikelola per part agar mencerminkan kemasan yang
  benar-benar pernah diterima.
- **Katalog manual terpisah dari riwayat Arrival.** Menambah type dari halaman
  Substitute tidak boleh membuat baris Arrival/invoice palsu, jadi katalog
  menyimpan asal nilai dan menggabungkan dua sumber saat dibaca.
- **UOM tidak diduplikasi.** Relasi membaca UOM dari master part pengganti agar
  tidak ada dua sumber kebenaran satuan.
- **Backfill konservatif.** Hanya relasi dengan satu nilai historis unik yang
  diisi otomatis; nilai ganda atau kosong diserahkan ke pengelola, sesuai
  keputusan wawancara untuk tidak menebak.
- **Packing type wajib pada relasi baru**, tetapi data lama tidak dipaksa diisi
  otomatis dengan nilai karangan.

## Phases

### Phase 1: Fondasi data

- [ ] Task 01: Katalog packing type per part dan skema atribut substitute
- [ ] Task 02: Server menerima ukuran dan packing type pada relasi substitute

**Checkpoint:** katalog dan kolom tersedia; endpoint menyimpan dan memvalidasi
field baru tanpa merusak relasi lama.

### Phase 2: Permukaan pengguna

- [ ] Task 03: Form Material Substitute menampilkan ukuran, UOM, dan packing type

**Final checkpoint:** setiap Success Criterion pada PRD diverifikasi.

## Parallelization

| Task | Category | Notes |
|---|---|---|
| 01 | Must be sequential | Menentukan skema dan model yang dibaca task lain |
| 02 | Must be sequential | Bergantung pada katalog dan kolom dari Task 01 |
| 03 | Needs coordination | Membaca kontrak props dari controller yang diubah Task 02 |

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Riwayat Arrival tidak lengkap sehingga katalog kosong untuk banyak part | Med | Katalog manual tersedia sejak Task 01; pengelola dapat melengkapi |
| Backfill salah menebak saat satu part punya beberapa kemasan | Med | Hanya nilai tunggal unik yang diisi; sisanya dibiarkan kosong |
| Validasi packing type memblokir edit relasi lama yang belum punya nilai | Med | Relasi lama boleh disimpan setelah memilih nilai; jangan menolak seluruh form |
| Defect `supplier_id` tidak tervalidasi pada `store()` | High | Diperbaiki di Task 02 dan dikunci test |

## Open Questions

Tidak ada. Open Question migrasi sudah diputuskan pada wawancara (nilai tunggal
unik diisi otomatis, nilai ganda diisi pengelola).

## Task Index

| # | Task | Size | Blocked by | Category | File |
|---|---|---|---|---|---|
| 01 | Katalog packing type per part dan skema atribut substitute | M | — | sequential | `task-01.md` |
| 02 | Server menerima ukuran dan packing type pada relasi substitute | S | 01 | sequential | `task-02.md` |
| 03 | Form Material Substitute menampilkan ukuran, UOM, dan packing type | M | 02 | coordination | `task-03.md` |
