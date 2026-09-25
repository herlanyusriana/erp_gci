# Implementation Plan: Material Tracker Clarity

## Overview

Memperjelas APK Material Tracker (`erp_gci_mobile`) dan API pendukungnya di ERP
(`erp_gci`). Tampilan tingkat atas **berbasis material** (permintaan Pak Arnold):
operator melihat kebutuhan material per tanggal D dan D+1, membuka WO di baliknya,
lalu memindai material dan **lokasi rak** saat mengeluarkan barang.

Capability map: `docs/prd/material-tracker-clarity.md`

PRD modul:

- `docs/prd/daily-planned-wo-feed.md` — selesai
- `docs/prd/material-scan-context.md` — selesai
- `docs/prd/material-daily-board.md` — baru
- `docs/prd/location-scan.md` — baru
- `docs/prd/tracker-dashboard.md` — direvisi menjadi layar issue saja

## Architecture Decisions

- **Baris papan = material acuan BOM**, dikunci per pasangan (part, satuan), dengan
  label nama + model dan ukuran terpisah.
- **Qty kebutuhan hari = `qty_required × (planned_qty ÷ qty WO)`.** Rasio porsi hari
  dipakai agar satuan tetap benar; tanpa itu angka material memakai qty WO penuh.
- **Kolom D dan D+1**, memakai aturan jadwal yang sama untuk dua tanggal.
- **Invoice berada di DETAILS, bukan di baris papan**, supaya daftar tetap ringan
  walau satu material punya banyak subspart dan tag.
- **Lokasi rak disimpan di `incoming_receives.location_code`** yang sudah ada, jadi
  tidak ada migrasi untuk data penerimaan. Kolom divalidasi terhadap master lokasi.
- **Lokasi tidak pernah memblokir pengeluaran.** Beda rak hanya menghasilkan
  peringatan; satu data basi tidak boleh menghentikan produksi.
- **Master lokasi dinonaktifkan, bukan dihapus**, agar dokumen lama tetap sah.
- **Tidak ada backfill lokasi.** Stok saat ini kosong (0 baris), jadi tidak ada data
  lama yang perlu ditata; layar Setup Lokasi disiapkan untuk celah berikutnya.
- **Task 7 dibatalkan.** Daftar berbasis WO digantikan papan material.

## Task List

### Phase 1: API Kontrak Data — SELESAI

- [x] Task 1: API — saring WO berdasarkan jadwal hari ini dan kirim qty hari itu
- [x] Task 2: API — daftar WO menunggu dijadwalkan
- [x] Task 3: API — `materials[]` material berstok pada release-context
- [x] Task 4: API — tag lengkap (invoice, supplier) dan urut FIFO
- [x] Task 3b: Ekstrak konteks material ke `ReleaseContextService` (refactor)
- [x] Task 3c: Perbaiki `part_number` pada `recommended_tags` (bug lama)

### Phase 2: APK Modul 1 dan 2 — SELESAI

- [x] Task 5: APK — daftar WO harian (Rencana D, Ter-issue, sisa)
- [x] Task 6: APK — kartu item material berstok

### Phase 3: Papan Material

- [x] Task 10: API — papan material harian (D dan D+1)
- [x] Task 11: API — DETAILS material (WO + subspart + invoice)
- [x] Task 12: APK — papan material (tabel D dan D+1)
- [x] Task 12b: APK — layar DETAILS dan navigasi ke alur scan

### Checkpoint: Papan Material

- [ ] Test API dan `flutter analyze` lulus
- [ ] Angka D/D+1 sesuai perhitungan porsi hari
- [ ] DETAILS memuat WO dan subspart beserta invoice

### Phase 4: Layar Issue

- [x] Task 8: APK — layar issue: empat angka, progres material, tag terlipat

### Checkpoint: Layar Issue

- [ ] "Sudah di-issue" dan "Ter-scan sesi ini" terpisah tegas
- [ ] Daftar tag terbuka per material

### Phase 5: Lokasi Rak

- [x] Task 13: Web — migrasi, model, dan permission lokasi
- [x] Task 13b: Web — CRUD master lokasi
- [x] Task 13c: Web — label QR lokasi
- [x] Task 14: Web — penerimaan menyimpan lokasi + layar Setup Lokasi
- [x] Task 15: API — resolve QR lokasi + catat lokasi saat pengeluaran
- [x] Task 16: APK — langkah scan lokasi pada alur issue

### Checkpoint: Lokasi Rak

- [ ] QR lokasi dapat dicetak dan di-resolve
- [ ] Pengeluaran mencatat lokasi dan tetap berhasil saat rak berbeda
- [ ] Pengeluaran tanpa lokasi tetap berhasil

### Phase 6: Verifikasi

- [x] Task 9: Verifikasi menyeluruh (otomatis lulus; 6 celah tercatat)

### Checkpoint: Selesai

- [ ] Seluruh Success Criteria keempat PRD aktif terpenuhi
- [ ] Seluruh gerbang mutu lulus
- [ ] Siap direview

## Task yang Dibatalkan

- **Task 7 — APK daftar WO harian (ringkasan, daftar menunggu, kartu WO).**
  Dibatalkan 2026-09-25: daftar tingkat atas berubah menjadi berbasis material,
  sehingga struktur yang dibangun task ini akan langsung dibongkar. Sebagian
  isinya berpindah ke Task 12 (kartu WO dipakai sebagai isi DETAILS).
  Model `WaitingWorkOrder` dan `plannedQtyTotal` yang sempat dibuat **tetap
  dipakai**, jadi tidak ada pekerjaan yang terbuang.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Rasio porsi hari salah hitung | Tinggi | Test dengan WO qty 100 dan planned_qty 40; pastikan satuan tidak berubah |
| Pembagian nol saat qty WO nol | Tinggi | Lewati WO dengan qty nol; ada test khusus |
| Papan memuat terlalu banyak baris | Sedang | Batasi pada material yang dibutuhkan; DETAILS memisahkan beban berat |
| Invoice di DETAILS membuat satu ketukan ekstra | Sedang | Keputusan disetujui; dicatat sebagai Open Question di PRD |
| N+1 pada papan (substitute + tag per material) | Tinggi | Query batch per kumpulan material; test jumlah query |
| Lokasi jadi syarat tidak sengaja | Tinggi | `location_code` opsional di kontrak; ada test pengeluaran tanpa lokasi |
| Master lokasi kosong saat dipakai | Sedang | Setup Lokasi menyediakan cara menata; validasi menolak kode tak dikenal |
| Perubahan skema `material_issues.location_code` | Sedang | Migrasi aditif dengan rollback; disetujui di PRD `location-scan` |
| Data stok lama tanpa lokasi | Rendah | Stok saat ini kosong; layar Setup Lokasi disiapkan untuk celah berikutnya |

## Open Questions

- Nasib penghitung "WO menunggu dijadwalkan" setelah papan berbasis material.
- Invoice langsung di baris papan, atau tetap di DETAILS.
- Porsi kebutuhan material per mesin (butuh perubahan skema).
- Perpindahan material antar rak sebagai transaksi tersendiri.
- Pola penomoran kode lokasi.
- Batas `per_page` daftar harian bila jumlah WO jauh melebihi perkiraan.
