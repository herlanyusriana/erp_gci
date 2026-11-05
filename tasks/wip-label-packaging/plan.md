# Implementation Plan: WIP Label Packaging & Multi-Container Printing

## Overview

Breakdown dari PRD `docs/prd/wip-label-packaging.md` menjadi 5 task vertikal terstruktur untuk mengimplementasikan manajemen kemasan fisik (Troli/Box/Pallet), pembagian multi-label kontainer (`LABEL 1 / N`), fleksibilitas sisa ganjil (cetak sekarang vs carry-over ke periode berikutnya), dan pencetakan akumulasi label NG.

## Architecture Decisions

- **Immutabilitas Qty Produksi:** Nilai riil produksi di `production_results.qty_good` tidak pernah diubah demi kemasan. Sisa ganjil yang ditunda dicatat pada kolom terpisah `label_carried_over_qty`.
- **Multi-Page Single Print Job:** Browser print menggunakan CSS `@page { size: 150mm 100mm; margin: 0; }` dan `.label-card { page-break-after: always; }` agar N label tercetak berurutan dalam sekali dialog print.
- **Slot Kanan Bawah:** Teks `# LOCATION #` diganti menjadi format kontainer `#labelnumber#` (`LABEL 1 / N`).
- **Tag Numbering:** Baris `TAG # : X` tetap mengacu pada nomor tag/batch invoice material asal yang dikonsumsi.

## Phases

### Phase 1: Data Model & Foundation
- [x] Task 01: Database & Model Carry-Over

### Phase 2: User Interface & Printing Engine
- [x] Task 02: Modal Dialog Packaging di Web
- [x] Task 03: Multi-Container Label Printing
- [x] Task 04: Cetak Akumulasi Label NG

### Phase 3: Verification & Polish
- [x] Task 05: Checkpoint & Verifikasi End-to-End

**Final checkpoint:** Seluruh Success Criteria pada `docs/prd/wip-label-packaging.md` terverifikasi hijau.

## Parallelization

| Task | Category | Notes |
|---|---|---|
| 01 | Must be sequential | Menyiapkan skema carry-over sisa label di database |
| 02 | Needs coordination | Membangun modal UI kemasan & kalkulasi di frontend (tergantung task 01) |
| 03 | Needs coordination | Mesin multi-label print HTML/CSS & backend controller (tergantung task 02) |
| 04 | Safe to parallelize | Cetak akumulatif reject NG di akhir shift/WO (hanya butuh task 01) |
| 05 | Must be sequential | Verifikasi end-to-end, test suite, build |

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Akumulasi sisa ganjil hilang jika WO selesai | Low | Pada periode terakhir WO, sistem otomatis mewajibkan sisa dicetak |
| Operator salah isi kapasitas kemasan | Med | Berikan validasi angka integer positif dan nilai default yang wajar |
| Page-break browser terpotong pada printer thermal | Med | Gunakan satuan mm presisi dan reset margin 0 pada CSS print |

## Task Index

| # | Task | Size | Blocked by | Category | File |
|---|---|---|---|---|---|
| 01 | Database & Model Carry-Over | S | — | Must be sequential | `task-01.md` |
| 02 | Modal Dialog Packaging di Web | M | 01 | Needs coordination | `task-02.md` |
| 03 | Multi-Container Label Printing | M | 02 | Needs coordination | `task-03.md` |
| 04 | Cetak Akumulasi Label NG | S | 01 | Safe to parallelize | `task-04.md` |
| 05 | Checkpoint & Verifikasi End-to-End | S | 03, 04 | Sequential | `task-05.md` |
