# Implementation Plan: Incoming Supplier-PO Filter & Work Order BOM Breakdown

## Overview

Rencana implementasi untuk memfilter PO berbasis Supplier dan sisa kuantitas di form Arrival, serta merombak tampilan material per proses di Work Order (menampilkan seluruh input proses Assembly, perlakuan khusus continuous tandem line untuk TPL, dan transisi dinamis Main Part BOM -> Substitute Part saat di-issue). Sumber PRD: `docs/prd/incoming-po-filter-and-wo-bom-breakdown.md`.

## Architecture Decisions

- **Perhitungan Remaining Qty PO:** Dihitung langsung di backend dengan mengagregasi kuantitas yang sudah dicatat di `incoming_arrival_items`. PO yang seluruh itemnya sudah terpenuhi (`remaining_qty <= 0`) disaring keluar dari form pembuatan Arrival baru.
- **Hierarki Input Arrival:** Supplier menjadi pintu masuk utama (`supplier_id` terpilih menentukan opsi `purchase_order_id`).
- **Grouping Proses WO:** 
  - Mesin yang terdeteksi sebagai `isTplPressLine` (TPL DONGSHIN & TPL KUKIL) digabung secara continuous line.
  - Mesin non-TPL (Assembly, Welding, Stamping tunggal) di-group per proses namun menjabarkan seluruh baris input (`work_order_items`) yang masuk ke proses tersebut.
- **Display Material Dinamis:** Status issue (`work_orders.status !== 'planned'` atau adanya rekaman issue/alokasi) memicu penggantian label utama dari `child_part` (Main Part) menjadi `selected_part`/alokasi (Substitute Part).

## Phases

### Phase 1: Incoming Supplier-PO Filter & Pipeline Status

- [ ] Task 01: Backend PO Remaining Qty & Supplier Filter (`task-01.md`)
- [ ] Task 02: Frontend Arrival Form Supplier-PO Reactive Filter & Pipeline Status (`task-02.md`)

**Checkpoint 01:** Form Arrival reaktif memfilter PO berdasarkan Supplier dan hanya memuat PO yang belum tuntas; status pipeline Arrival -> IQC Cek -> Received tampil rapi.

### Phase 2: Work Order BOM Breakdown & Material Transition

- [ ] Task 03: Work Order Process Breakdown: Multi-Input Assembly & TPL Continuous Line (`task-03.md`)
- [ ] Task 04: Dynamic Material Display: Main BOM Part -> Substitute on Issue (`task-04.md`)

**Final Checkpoint:** Seluruh komponen proses Assembly tampil lengkap di WO Show; TPL tetap 1 baris tandem; tampilan Main Part bertransisi ke Substitute saat di-issue; Pest tests 100% hijau; npm run build sukses.

## Parallelization

| Task | Category | Notes |
|---|---|---|
| 01 | Must be sequential | Menyediakan data PO berfilter supplier & sisa qty |
| 02 | Needs coordination | Membutuhkan struktur data PO dari Task 01 |
| 03 | Safe to parallelize | Dapat dikerjakan bersamaan dengan modul incoming |
| 04 | Needs coordination | Bergantung pada penyusunan baris proses di Task 03 |

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Perhitungan sisa PO lambat bila histori arrival besar | Med | Gunakan subquery agregasi yang terindeks pada `arrival_id` dan `purchase_order_id` |
| Tabel WO Show terlalu padat saat assembly memiliki banyak material | Low | Gunakan visual hierarchy (warna latar lembut / indentasi) untuk membedakan baris header proses dan baris material input |

## Task Index

| # | Task | Size | Blocked by | Category | File |
|---|---|---|---|---|---|
| 01 | Backend PO Remaining Qty & Supplier Filter | S | — | sequential | `task-01.md` |
| 02 | Frontend Arrival Form Supplier-PO Reactive Filter & Pipeline Status | M | 01 | needs coordination | `task-02.md` |
| 03 | Work Order Process Breakdown: Multi-Input Assembly & TPL Continuous Line | M | — | parallel | `task-03.md` |
| 04 | Dynamic Material Display: Main BOM Part -> Substitute on Issue | S | 03 | needs coordination | `task-04.md` |
