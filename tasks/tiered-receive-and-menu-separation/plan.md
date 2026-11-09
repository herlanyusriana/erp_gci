# Implementation Plan: Tiered Packaging Receive & Arrival-Receive Menu Separation

## Overview

Rencana implementasi untuk memisahkan menu Arrival dan Receive secara mandiri, menerapkan hierarki input kemasan bertingkat 3 level (Bundle $\times$ Packing per Bundle $\times$ Qty per Packing = Total Qty), standarisasi satuan berat mutlak KGM, serta pencantuman rincian kemasan pada label QR. Sumber PRD: `docs/prd/tiered-receive-and-menu-separation.md`.

## Architecture Decisions

- **Pemisahan Domain Menu:** Halaman `Arrival/Show.vue` difokuskan 100% pada pencatatan data/dokumen kedatangan. Tombol receive dan tabel tag dipindahkan ke workspace mandiri di modul Receive (`/receive/arrivals/{arrival}` atau alur khusus di modul Receive).
- **Atribut Kemasan Bertingkat di Database:** Tabel `incoming_receives` diperluas dengan kolom `bundle_count`, `packing_count`, `packing_type`, dan `packing_qty` yang bersifat nullable demi kompatibilitas data lama.
- **Kalkulasi Reaktif di Frontend:** Form Receive otomatis menghitung Total Qty dari input kemasan bertingkat dengan tetap memberikan keleluasaan bagi operator untuk mengedit Total Qty secara manual jika terdapat sisa ganjil (*odd lot*).
- **Standarisasi Satuan Berat:** Kolom `net_weight` dan `gross_weight` selalu menggunakan unit `KGM`.
- **String Kemasan pada Label:** Template label thermal menyertakan format ringkasan kemasan bila datanya tersedia.

## Phases

### Phase 1: Foundation & Menu Separation

- [ ] Task 01: Database Migration & Model: Multi-Tier Packaging Fields (`task-01.md`)
- [ ] Task 02: Menu Separation: Clean Arrival Show & Dedicated Receive Workspace (`task-02.md`)

**Checkpoint 01:** Database siap; Arrival Show bersih dari aksi receive; alur Receive mandiri sudah aktif.

### Phase 2: Multi-Tier Packaging & Verification

- [ ] Task 03: Frontend Multi-Tier Packaging Form (Bundle x Packing x Qty) & KGM Weight (`task-03.md`)
- [ ] Task 04: QR Tag Label Packaging String & Automated Regression Tests (`task-04.md`)

**Final Checkpoint:** Seluruh Success Criteria di PRD terverifikasi, label QR memuat detail kemasan, dan 100% test Pest lulus hijau.

## Parallelization

| Task | Category | Notes |
|---|---|---|
| 01 | Must be sequential | Fondasi skema database kemasan bertingkat |
| 02 | Needs coordination | Menata ulang halaman dan route Receive |
| 03 | Needs coordination | Membutuhkan Task 01 dan 02 selesai |
| 04 | Safe to parallelize | Template label & penulisan feature test |

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Barang tanpa sub-kemasan membuat input terasa bertele-tele | Med | Sediakan default `packing_count = 1` dan auto-populate jenis kemasan |
| Data lama tidak memiliki rincian bundle & packing | Low | Kolom dibuat nullable; tampilan label otomatis fallback ke format lama jika atribut kosong |

## Task Index

| # | Task | Size | Blocked by | Category | File |
|---|---|---|---|---|---|
| 01 | Database Migration & Model: Multi-Tier Packaging Fields | S | — | sequential | `task-01.md` |
| 02 | Menu Separation: Clean Arrival Show & Dedicated Receive Workspace | M | 01 | needs coordination | `task-02.md` |
| 03 | Frontend Multi-Tier Packaging Form (Bundle x Packing x Qty) & KGM Weight | M | 01, 02 | needs coordination | `task-03.md` |
| 04 | QR Tag Label Packaging String & Automated Regression Tests | S | 03 | parallel | `task-04.md` |
