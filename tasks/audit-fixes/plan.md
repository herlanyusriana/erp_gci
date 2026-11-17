# Implementation Plan: Perbaikan Hasil Audit ERP GCI

## Overview

Rencana perbaikan berdasarkan audit menyeluruh (8 reviewer: frontend, backend
architecture, database, UI, UX, senior code review, adversarial review,
software architecture). Fokus: **membuat trial bisa jalan dan stok tidak
melenceng di hari pertama**, lalu menutup celah keamanan dan integritas data.

Bukan pekerjaan yang sama dengan `tasks/plan.md` (Material Tracker Clarity —
APK, sisa verifikasi visual). Plan itu **tidak disentuh**. Repo ini memakai
konvensi satu folder per plan (lihat `tasks/incoming-po-filter-and-wo-bom-breakdown/`),
jadi plan ini hidup di `tasks/audit-fixes/`.

Task list: `tasks/audit-fixes/todo.md` (bukan `tasks/todo.md`, yang dipakai
plan Material Tracker).

## Sumber Temuan

Semua temuan punya bukti `file:line` dan sudah diverifikasi ulang saat
penyusunan plan ini:

| # | Temuan | Status verifikasi |
|---|---|---|
| B1 | Stok FG bisa keluar tanpa terpotong / terpotong 2× | `DeliveryService.php:92-104`, `:143`, `:71-113` |
| B2 | Production Result memanggil route yang tidak ada | `Result/Create.vue:62,70` vs `route:list` |
| B3 | Release WO di web = jalan buntu | `WorkOrder/Show.vue:135` → `WoService::releaseWorkOrder` (tanpa `material_issues`) |
| B4 | Local PO tanpa pintu masuk | nol referensi `local-pos` di luar folder `LocalPo/` |
| B5 | OQC mustahil bagi role qc | `Delivery/Show.vue:128-129,223-238` dead code; ppic/qc tanpa `delivery.update` |
| B6 | Gate hilang di 5 controller | `MachineController` 0, `ProcessController` 0, `UomController` 0, `ConfigMaster` 1 (index saja), `Supplier` 1 (index saja) |
| B7 | Kredensial seed publik | `RolesAndPermissionsSeeder.php:99,122`, `ProductionOperatorSeeder.php:12` |
| H1 | PPIC terkunci dari Outgoing | `Launcher.vue:57` gate `['stock.issue']`; ppic punya `delivery.*` tanpa `stock.issue` |
| H2 | `CustomerPrice` menghapus input | `CustomerPrice/Index.vue:52-61` watch `props.parts` |
| H3 | Tag tidak unik di DB | `part_stocks` hanya unique `pkey` |
| H4 | Hapus dokumen hulu menyisakan stok | `IncomingArrivalController:607-614`, `LocalPoController:349-353` |
| H5 | Reset tanpa guard environment & audit | `ResetTransactionalData.php` tanpa cek `APP_ENV` |
| H6 | Tag tidak bisa pakai index | query `LOWER(COALESCE(tag,''))` vs btree `tag` |
| M1 | 10 key i18n hilang → render path mentah | `production.notes`, `outgoing.allStatuses`, dll |
| M2 | Generator nomor race | `WorkOrder.php:56`, `Delivery.php:235`, `PickingList.php:52` |
| M3 | `whereDate()` mematikan index | `WipInventoryService`, `DeliveryService` |

## Architecture Decisions

- **Potongan stok per tag, bukan per dokumen.** Flag `deliveries.stock_deducted_at`
  diganti sumber kebenaran per scan: `delivery_scans.deducted_qty`. Satu-satunya
  cara menutup empat skenario sekaligus (tag lintas delivery, hapus scan setelah
  reject, move ganda, return parsial).
- **Tolak, jangan clamp.** `min(scan.qty, stock.qty)` diganti validasi yang
  melempar `ValidationException` bila stok kurang. Clamp diam-diam adalah sumber
  stok hilang tanpa jejak.
- **Lock baris delivery sebelum memotong.** `Delivery::lockForUpdate()` di dalam
  transaksi `moveToPreparation` dan `recordScan`, supaya tombol "Pindahkan" dan
  scan terakhir tidak memotong dua kali.
- **OQC tetap bisa dari web.** Panel sudah ditulis tapi tidak dirender; render
  dan beri role `qc` permission yang tepat. Ketergantungan tunggal pada APK
  adalah risiko operasional (tablet habis baterai = pengiriman berhenti).
- **Release WO: satu jalur saja.** Web release tidak membuat bon → WO tidak bisa
  dilaporkan. Pilihan: hapus tombol web, atau buat web release menghasilkan
  `material_issues` yang sama. Plan ini memilih **sembunyikan tombol web** (biaya
  S, tanpa risiko data) dan mencatat opsi kedua sebagai backlog.
- **Gate dulu, refactor kemudian.** Menambah `Gate::authorize` di 5 controller
  adalah biaya S dengan dampak keamanan langsung. Memecah `app/Services` menjadi
  `app/Domain/*` (usulan Software Architect) ditunda sampai ada developer kedua.
- **Index hanya yang menyentuh jalur terpanas.** Trial berjalan dengan tabel
  transaksi kosong; menambah 23 index FK sekarang nol manfaat. Yang dikerjakan:
  tag lookup, `production_results.output_part_stock_id`, dan `whereDate` → range.
- **`stock_movements` (ledger append-only) ditunda.** Ini keputusan arsitektur
  besar (M) yang mengubah 5 titik tulis. Untuk trial, `part_stocks` + guard yang
  benar sudah cukup; dicatat sebagai backlog dengan alasan eksplisit.

## Task List

Ringkas di sini; detail acceptance criteria + verifikasi ada di `todo.md`.

### Phase 1: Integritas Stok (risiko tertinggi, fail fast)
- [ ] Task 01: `delivery_scans.deducted_qty` + tolak under-deduct
- [ ] Task 02: Lock delivery + `returnToOrigin` memakai qty potong nyata
- [ ] Task 03: Eksklusivitas tag & troli lintas delivery

### Checkpoint 1: Stok
- [ ] Test baru membuktikan: tag lintas delivery ditolak, return parsial seimbang, move ganda tidak memotong 2×
- [ ] `vendor/bin/pest` hijau
- [ ] Review manusia sebelum lanjut

### Phase 2: Alur yang Putus (blocker fungsional)
- [ ] Task 04: Perbaiki route Production Result (XS)
- [ ] Task 05: Sembunyikan release WO di web + jelaskan jalurnya
- [ ] Task 06: Render panel OQC di web + permission role qc
- [ ] Task 07: Pintu masuk Local PO
- [ ] Task 08: Container inspection UI
- [ ] Task 09: Buka akses Outgoing untuk PPIC

### Checkpoint 2: Alur
- [ ] Satu siklus penuh bisa diselesaikan dari UI (dengan APK)
- [ ] `vendor/bin/pest` + `npm run build` hijau
- [ ] Review manusia: coba alur di browser

### Phase 3: Keamanan & Operasional
- [ ] Task 10: Gate di 5 controller + test matriks permission
- [ ] Task 11: Kredensial seed dari env
- [ ] Task 12: Guard `erp:reset-data` (env + audit trail)

### Checkpoint 3: Keamanan
- [ ] Test matriks membuktikan role non-admin ditolak di semua mutasi master
- [ ] Reset menolak berjalan di production tanpa flag eksplisit
- [ ] Review manusia sebelum deploy trial

### Phase 4: Integritas Data Lanjutan
- [ ] Task 13: Unique `(part_id, tag)` + guard hapus dokumen hulu
- [ ] Task 14: Generator nomor atomik

### Checkpoint 4: Data
- [ ] Unique constraint terpasang, tidak ada duplikat tersisa
- [ ] Dua create bersamaan tidak menghasilkan 500

### Phase 5: UX, i18n, Performa
- [ ] Task 15: 10 key i18n hilang + perluas `check-i18n.mjs`
- [ ] Task 16: `CustomerPrice` berhenti menghapus input
- [ ] Task 17: Index tag + `output_part_stock_id` + `whereDate` → range

### Checkpoint 5: Selesai
- [ ] Semua acceptance criteria terpenuhi
- [ ] `vendor/bin/pest`, `npm run build`, `vendor/bin/pint` hijau
- [ ] Siap deploy trial

## Backlog (sengaja ditunda, dengan alasan)

| Item | Alasan ditunda |
|---|---|
| `stock_movements` append-only | Mengubah 5 titik tulis + migration data; butuh keputusan produk. Trial bisa jalan tanpa ini bila Task 01–03 selesai. |
| Pecah `app/Services` → `app/Domain/*` | Nilai baru muncul saat ada developer kedua. Sekarang hanya churn. |
| 23 index FK + index list (`deliveries.delivery_date`, `work_orders.created_at`, `picking_lists.picking_date`) | Tabel transaksi kosong; nol biaya hari ini. Kerjakan sebelum data nyata, bukan sebelum trial. |
| `pg_stat_statements` | Opsional; berguna saat trial berjalan untuk mengukur query lambat. Bisa dipasang kapan saja. |
| Integrasi API Trolley Scan (`updated_since` + resolve fallback) | CSV + aturan kepemilikan field cukup untuk trial. Bangun saat churn troli > ~10/minggu. |
| Queue untuk PDF/Excel/QR | Volume trial rendah; queue hanya bila ada request > 8 detik. |
| Refactor `MaterialIssueSummaryService` | Perbaiki `rowForWorkOrder` saja bila perlu; rewrite penuh saat p95 > 1 detik. |
| `DeliveryApiController` lengkapi endpoint QC/CEISA/submit | APK saat ini setengah alur; web sudah menutupi. Butuh keputusan produk apakah APK harus menuntaskan. |
| Hapus ~45 string Indonesia hardcoded di Receive/Arrival/IQC | Penting untuk user Korea, tapi bukan blocker trial satu bahasa. Task 15 mengerjakan yang benar-benar merusak UI (key hilang). |
| Standarisasi komponen visual (51 tombol primer, 6 varian) | Kosmetik; gain tinggi tapi tidak memblokir. Kerjakan setelah trial stabil. |

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Task 01 mengubah skema `delivery_scans` saat ada data trial berjalan | High | Migration aditif (`deducted_qty` nullable). Backfill dari `deliveries.stock_deducted_at` + `scan.qty` untuk baris lama, atau set 0 bila ragu. |
| Perubahan `moveToPreparation` memecah test yang ada | Med | `DeliveryTest` + `PickingListTest` sudah mengunci perilaku; jalankan keduanya setelah tiap task. |
| Menyembunyikan tombol release web membuat planner kehilangan jalur | Med | Sertakan teks di WO Show: "Release & issue material dilakukan dari APK (scan tag)". |
| Menambah Gate bisa mengunci role yang sekarang bisa kerja | High | Task 10 menyertakan test matriks per role, dan seeder permission diperbarui dalam task yang sama. |
| Unique `(part_id, tag)` gagal dipasang karena data duplikat | Med | Cek duplikat dulu (`SELECT ... GROUP BY HAVING count>1`); bila ada, tangani manual sebelum constraint. |
| Kredensial dari env membuat dev lokal tidak bisa login | Low | Fallback ke `password` hanya bila `APP_ENV=local`; dokumentasikan di AGENTS.md. |

## Open Questions

1. **Release WO**: sembunyikan tombol web (plan ini), atau buat web release
   menghasilkan `material_issues` seperti APK? Yang kedua lebih benar secara UX
   tapi biaya M dan menduplikasi logika scan.
2. **OQC permission**: beri role `qc` `delivery.update` (sederhana, tapi memberi
   qc hak ubah dokumen lain), atau permission baru `delivery.oqc` (lebih bersih,
   perlu migration + seeder + policy)?
3. **`stock_movements`**: apakah manajemen butuh jejak audit stok untuk trial,
   atau cukup setelah trial?
4. **Kredensial**: password awal dari env — apakah perlu paksa ganti saat login
   pertama (butuh kolom `must_change_password` + middleware)?
