# Implementation Plan: Unified Purchase Order, Streamlined Local Arrival, and Incoming Quality (IQC Pass)

## Overview

Rencana pelaksanaan berdasarkan PRD `docs/prd/unified-po-arrival-iqc.md` untuk menyatukan menu Purchase Order (mendukung supplier lokal & impor), menyederhanakan alur kedatangan lokal di menu Arrival (auto-populate dari PO, input No PEN, No AJU, Surat Jalan/Invoice), menambahkan modul `Incoming Quality` untuk ceklis `IQC Pass`, dan mengaitkan IQC Pass ke gerbang Receive gudang.

## Architecture Decisions

- **Unifikasi Purchase Order:** `PurchaseOrderController` membuka filter `source` sehingga mencakup supplier lokal dan impor. Menu lama `local-pos` dihapus dari routing dan launcher.
- **Kedatangan Lokal vs Impor di Arrival:** Form Arrival memiliki switcher tab `[ Lokal ]` vs `[ Impor ]`. Memilih PO lokal otomatis mengisikan item barang, dan form lokal hanya menampilkan Tanggal, Supplier, No PO, Surat Jalan/Invoice, No PEN, No AJU, dan tabel item tanpa panel kapal/kontainer.
- **Modul Incoming Quality:** Route `GET /incoming-quality` dan `POST /incoming-quality/{arrival}/pass` untuk tim QC. Tampilan tabel ringkas: Tanggal Arrival, Supp Name, PO No, Item List, dan tombol ceklis `IQC Pass`.
- **Integrasi Receive Gudang:** `ReceiveController` memeriksa `iqc_status === 'passed'` sebelum mengizinkan penerimaan dan cetak tag barcode.

## Phases

### Phase 1: Database & Model Foundation
- [x] Task 01: Database Schema & Pembersihan Data Lama

### Phase 2: Purchase Order Unification
- [x] Task 02: Unifikasi Purchase Order (Lokal & Impor)

### Phase 3: Quality Control & IQC Gate
- [x] Task 03: Menu & Ceklis Incoming Quality (IQC Pass)

### Phase 4: Streamlined Arrival
- [x] Task 04: Penyederhanaan Form Kedatangan (Arrival)

### Phase 5: Verification & End-to-End Test
- [x] Task 05: Integrasi IQC Pass ke Receive Gudang & Verifikasi End-to-End

**Final checkpoint:** Seluruh Success Criteria pada `docs/prd/unified-po-arrival-iqc.md` terverifikasi hijau.

## Task Index

| # | Task | Size | Blocked by | Category | File |
|---|---|---|---|---|---|
| 01 | Database Schema & Pembersihan Data Lama | S | — | Must be sequential | `task-01.md` |
| 02 | Unifikasi Purchase Order (Lokal & Impor) | M | 01 | Needs coordination | `task-02.md` |
| 03 | Menu & Ceklis Incoming Quality (IQC Pass) | M | 01 | Safe to parallelize | `task-03.md` |
| 04 | Penyederhanaan Form Kedatangan (Arrival) | M | 02 | Needs coordination | `task-04.md` |
| 05 | Integrasi IQC Pass ke Receive Gudang & Verifikasi End-to-End | M | 03, 04 | Sequential | `task-05.md` |
