# Implementation Plan: Production Material Receipt (APK)

## Overview

Menambahkan alur **penerimaan material oleh production** di APK Material Tracker.
Setelah warehouse melakukan issue-out material (scan tag → release WO), operator
produksi di lantai membuka APK, scan label material (tag), lalu scan QR mesin
tujuan untuk mencatat bahwa material sudah sampai di mesin tersebut.

Alur ini menjembatani issue-out warehouse dengan konsumsi material di lantai
produksi. Tidak ada input qty — sistem membaca qty dari dokumen issue. Scan
per tag sehingga satu dokumen issue bisa dialokasikan ke mesin berbeda.

## PRD

`docs/prd/production-material-receipt.md`

## Dependency Graph

```
ProductionMaterialReceipt (tabel baru -> issue material + machine)
    │
    ├── ProductionMaterialReceiptController (API endpoint)
    │       │
    │       ├── GET  /api/receipts?machine_id=  (daftar penerimaan per mesin)
    │       ├── POST /api/stock-tags/resolve    (existing — untuk scan tag)
    │       ├── POST /api/machines/resolve      (existing — untuk scan QR mesin)
    │       └── POST /api/receipts/confirm      (konfirmasi terima tag + mesin)
    │
    ├── Flutter model (production_receipt.dart, receipt_tag_info.dart)
    │
    └── Flutter screen (production_receipt_screen.dart)
            ├── Scan tag material -> resolve tag
            ├── Scan QR mesin -> resolve machine
            └── List penerimaan per mesin (home baru atau sub-menu)
```

## Architecture Decisions

1. **Tabel `production_material_receipts`**: mencatat setiap penerimaan (tag, part_id, machine_id, material_issue_item_id, diterima_oleh, diterima_pada). Ini adalah jejak audit, bukan stok — stok sudah di-book saat issue-out dan akan dikonsumsi saat Production Result.
2. **Tidak ada perubahan pada tabel `material_issues` atau `material_issue_items`**: penerimaan production adalah catatan terpisah.
3. **Validasi tag**: tag harus sudah di-issue-out (ada di `material_issue_items`) dan belum pernah diterima sebelumnya untuk mesin yang sama.
4. **Daftar penerimaan per mesin**: endpoint GET mengembalikan semua penerimaan hari ini untuk mesin tertentu, diurutkan dari yang terbaru.
5. **Separasi authorization**: endpoint penerimaan memakai permission `stock.issue` atau permission baru `production.receive` (ikuti pola existing: reuse `stock.issue` untuk konsistensi dengan issue-out).

## Task List

### Phase 1: Foundation — Backend

#### Task 1: Migrasi tabel + Model `ProductionMaterialReceipt`

**Description:** Buat migrasi tabel `production_material_receipts` dengan kolom: id,
material_issue_item_id (FK ke material_issue_items), tag, part_id, machine_id,
received_by (user_id), received_at, notes. Beserta model Eloquent dengan relasi
ke `MaterialIssueItem`, `Part`, `Machine`, dan `User`.

**Acceptance criteria:**
- [ ] Migrasi berjalan maju dan mundur tanpa kehilangan data
- [ ] Model memiliki relasi: materialIssueItem, part, machine, receiver (User)
- [ ] Kolom unique constraint: (material_issue_item_id, machine_id) — satu tag
      hanya bisa diterima sekali per mesin

**Verification:**
- [ ] `php artisan migrate` sukses
- [ ] `php artisan migrate:rollback` sukses
- [ ] Test unit: model dapat di-create dan dibaca

**Dependencies:** None

**Estimated scope:** Small (2-3 files)

---

#### Task 2: Service `ProductionReceiptService`

**Description:** Service class yang menangani logika bisnis penerimaan: validasi
bahwa tag sudah di-issue-out (cek `material_issue_items`), belum diterima untuk
mesin yang sama, dan menyimpan receipt. Juga method untuk mengambil daftar
penerimaan per mesin (hari ini atau range tanggal).

**Acceptance criteria:**
- [ ] `confirmReceipt(tag, machineId, userId)` — validasi + simpan
- [ ] `receiptsByMachine(machineId, date)` — daftar penerimaan per mesin
- [ ] Validasi: tag yang tidak dikenal (tidak ada di `material_issue_items`) → error
- [ ] Validasi: tag yang sudah diterima mesin yang sama → error duplikat
- [ ] Validasi: mesin nonaktif → error

**Verification:**
- [ ] Unit test service dengan fake data
- [ ] Feature test endpoint setelah task 3

**Dependencies:** Task 1

**Estimated scope:** Small (2 files: service + test)

---

#### Task 3: API Endpoint `ProductionReceiptController`

**Description:** Controller API dengan dua endpoint:
- `POST /api/receipts/confirm` — menerima `{tag, machine_id, notes?}` → simpan receipt
- `GET /api/receipts?machine_id=X&date=Y` — daftar penerimaan

Menggunakan `resolveTag` dan `resolveMachine` yang sudah ada untuk validasi
tag/mesin sebelum konfirmasi. Permission `stock.issue`.

**Acceptance criteria:**
- [ ] Confirm receipt: tag valid + machine valid → 200 + data receipt
- [ ] Confirm receipt: tag belum di-issue → 404/422
- [ ] Confirm receipt: duplikat (tag+machine sudah pernah) → 422
- [ ] List receipts by machine: mengembalikan array receipt hari itu
- [ ] List receipts: filter date opsional
- [ ] Authorization: Sanctum + permission stock.issue

**Verification:**
- [ ] Feature test mencakup semua skenario di atas
- [ ] `php artisan test --filter=ProductionReceiptApiTest`
- [ ] Pint + build

**Dependencies:** Task 2

**Estimated scope:** Medium (3-4 files: controller, route, test)

---

### Checkpoint 1: Backend Foundation
- [ ] Migrasi jalan
- [ ] Service + API berfungsi
- [ ] Test backend lulus

---

### Phase 2: Flutter — Integration & Screens

#### Task 4: Flutter Model + Repository

**Description:** Tambah model `ProductionReceipt` dan `ReceiptConfirm` di
`lib/models/`. Tambah method `confirmReceipt(tag, machineId)` dan
`receiptsByMachine(machineId, date)` di repository baru
`lib/repositories/production_receipt_repository.dart` atau perluas
`issue_repository.dart` (ikuti pola existing).

**Acceptance criteria:**
- [ ] `ProductionReceipt.fromJson` mem-parse semua field dari API
- [ ] `confirmReceipt()` mengirim POST dengan benar
- [ ] `receiptsByMachine()` mengembalikan list receipt
- [ ] Error handling: 404 → tag tidak dikenal, 422 → duplikat

**Verification:**
- [ ] Model test (fromJson)
- [ ] `flutter analyze` no issues

**Dependencies:** Task 3

**Estimated scope:** Small (2 files)

---

#### Task 5: Provider `ProductionReceiptNotifier`

**Description:** Riverpod `Notifier` untuk state penerimaan: loading, list receipt
per mesin, error. Method `confirm(tag, machineId)` yang memanggil repository,
dan `loadReceipts(machineId, date)`.

**Acceptance criteria:**
- [ ] State: idle → loading → success/error
- [ ] Confirm success → reset field + reload list
- [ ] Confirm duplikat → error state dengan pesan
- [ ] Confirm tag invalid → error state

**Verification:**
- [ ] Unit test notifier dengan FakeRepository
- [ ] `flutter test` pada notifier test

**Dependencies:** Task 4

**Estimated scope:** Small (1-2 files)

---

#### Task 6: Scanner Screen — Scan Tag + Scan Mesin Berurutan

**Description:** Satu layar di APK dengan alur:
1. Awal: tombol "Scan Tag Material" → open scanner
2. Setelah tag discan, tampilkan info tag (part, qty, invoice dari resolusi API)
3. Tombol "Scan QR Mesin" → open scanner
4. Setelah mesin discan, tampilkan info mesin (nama, kode)
5. Tombol "Konfirmasi Penerimaan" → panggil API confirm
6. Setelah sukses → kembali ke layar penerimaan (Task 7) atau ulang scan

Integrasi dengan `ScanScreen` existing. Mengikuti pola yang sudah ada di
`issue_screen.dart` (panggil scanner via Navigator.push, parse hasil).

**Acceptance criteria:**
- [ ] Scan tag → resolve → tampilkan part name, qty, invoice
- [ ] Scan mesin → resolve → tampilkan machine name
- [ ] Konfirmasi → API call → sukses
- [ ] Error state: tag tidak dikenal, mesin tidak dikenal, duplikat
- [ ] Loading state selama resolve dan konfirmasi

**Verification:**
- [ ] Widget test dengan mocked repository
- [ ] `flutter analyze` no issues
- [ ] `flutter test` lulus

**Dependencies:** Task 4, 5

**Estimated scope:** Medium (3-4 files)

---

#### Task 7: Daftar Penerimaan per Mesin (Home/List Screen)

**Description:** Layar yang menampilkan daftar material yang sudah diterima oleh
sebuah mesin pada hari itu. Bisa diakses dari:
- Hasil sukses setelah Task 6 (lihat detail)
- Menu utama APK, misalnya tab baru atau sub-menu "Penerimaan"
- Perlu picker/pilih mesin (dropdown atau scan QR mesin)

Tampilan: card per receipt dengan tag, part, invoice, supplier, timestamp.

**Acceptance criteria:**
- [ ] Pilih mesin (dropdown dari daftar mesin aktif, atau scan) → tampilkan list
- [ ] List receipt hari ini dari API
- [ ] Setiap card menampilkan: tag, part number, part name, qty, invoice, supplier, timestamp
- [ ] Empty state: "Belum ada penerimaan untuk mesin ini hari ini"
- [ ] Loading state
- [ ] Error state

**Verification:**
- [ ] Widget test
- [ ] `flutter analyze` no issues
- [ ] `flutter test` lulus

**Dependencies:** Task 4

**Estimated scope:** Medium (2-3 files)

---

### Checkpoint 2: Complete
- [ ] Backend: 251+ test lulus
- [ ] Flutter: all existing + new test lulus
- [ ] `flutter analyze` no issues
- [ ] `npm run build` lulus
- [ ] Manual flow: issue-out → buka APK → scan tag → scan mesin → konfirmasi → lihat daftar

---

## Risks and Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Tag yang sama diterima oleh dua mesin berbeda secara simultan | Data tidak konsisten | Unique constraint (material_issue_item_id, machine_id) + validasi di service |
| Operator salah scan QR (scan QR part bukan tag) | ResolveTag gagal 404 | APK menampilkan error jelas; scanner hanya untuk label material/mesin |
| Machine_id di QR label mesin berbeda dengan yang ada di BOM | ResolveMachine sukses tapi mesin tidak relevan | Tidak dicegah — operator harus memindai QR yang ditempel di mesin fisik |
| Permission stock.issue belum di-seed untuk role operator produksi | 403 | Catat di deployment notes; tambahkan permission ke role production |

## Open Questions

- Apakah permission `stock.issue` cukup untuk penerimaan production, atau perlu permission baru (`production.receive`)? Sementara pakai `stock.issue` untuk konsistensi.
- Format tampilan daftar per mesin: apakah perlu filter tanggal atau hanya hari ini?
  Sementara hanya hari ini, plus filter tanggal opsional dari API.