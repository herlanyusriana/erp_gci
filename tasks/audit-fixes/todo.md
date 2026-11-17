# Task List: Perbaikan Hasil Audit ERP GCI

Plan: `tasks/audit-fixes/plan.md`

Sumber temuan: audit 8 reviewer (frontend, backend architect, database optimizer,
UI designer, UX architect, senior developer, code reviewer, software architect).
Semua `file:line` di bawah sudah diverifikasi ulang saat plan disusun.

Konvensi verifikasi repo:
- Test: `vendor/bin/pest <path>` (suite penuh: `vendor/bin/pest`)
- Lint PHP: `vendor/bin/pint --dirty --format agent`
- Build frontend: `npm run build`
- Mobile: `cd ../erp_gci_mobile && flutter analyze && flutter test`

---

# Phase 1: Integritas Stok

## Task 01: Potong stok per tag (`deducted_qty`), tolak bila stok kurang

**Description:** `DeliveryService::moveToPreparation()` memotong stok dengan
`$take = min($scan->qty, $stockQty)` lalu `continue` bila hasilnya ≤ 0 — tanpa
error. Akibatnya dokumen tetap berstatus `picked` walau tidak ada stok yang
dipotong. Tambahkan kolom `delivery_scans.deducted_qty` sebagai satu-satunya
catatan berapa yang benar-benar dipotong per tag, dan tolak pemindahan bila
stok tidak mencukupi.

**Acceptance criteria:**
- [x] Kolom `delivery_scans.deducted_qty` (decimal, nullable) ada lewat migration aditif.
- [x] `moveToPreparation` mengisi `deducted_qty` per scan dan mengurangi `part_stocks.qty` sebesar nilai itu.
- [x] Bila `part_stocks.qty < scan.qty`, pemindahan **ditolak** dengan `ValidationException` yang menyebut tag dan sisa stok — bukan dipotong sebagian.
- [x] Memanggil `moveToPreparation` dua kali tidak memotong dua kali (dijaga `stock_deducted_at` + `deducted_qty` sudah terisi).
- [x] Test: dua delivery memindai tag yang sama → delivery kedua ditolak, stok tidak negatif.

**Verification:**
- [x] `vendor/bin/pest tests/Feature/DeliveryTest.php tests/Feature/PickingListTest.php`
- [x] `vendor/bin/pint --dirty --format agent`

**Dependencies:** None

**Files likely touched:**
- `database/migrations/*_add_deducted_qty_to_delivery_scans.php`
- `app/Services/DeliveryService.php`
- `app/Models/DeliveryScan.php`
- `tests/Feature/DeliveryTest.php`

**Estimated scope:** Medium (4 files)

---

## Task 02: Lock baris delivery + `returnToOrigin` memakai qty potong nyata

**Description:** Dua celah sisa: (a) `moveToPreparation` tidak mengunci baris
`deliveries`, sehingga tombol "Pindahkan" di web dan scan terakhir dari APK bisa
berjalan bersamaan dan memotong dua kali; (b) `returnToOrigin` menambahkan
`$scan->qty` (niat scan), bukan qty yang benar-benar dipotong, sehingga
pengembalian bisa melebihi pengambilan.

**Acceptance criteria:**
- [x] `moveToPreparation` dan `recordScan` memanggil `Delivery::lockForUpdate()` di dalam transaksi sebelum membaca `stock_deducted_at`.
- [x] `returnToOrigin` mengembalikan `deducted_qty` (bukan `qty`) dan mengosongkan `deducted_qty` setelah dikembalikan.
- [x] `returnToOrigin` memakai `withTrashed()` saat mencari `PartStock` agar baris yang sudah di-soft-delete tetap bisa dipulihkan.
- [x] Test: return setelah potongan parsial mengembalikan qty yang sama dengan yang dipotong, bukan qty scan.

**Verification:**
- [x] `vendor/bin/pest tests/Feature/DeliveryTest.php tests/Feature/PickingListTest.php`
- [x] `vendor/bin/pint --dirty --format agent`

**Dependencies:** Task 01

**Files likely touched:**
- `app/Services/DeliveryService.php`
- `tests/Feature/DeliveryTest.php`

**Estimated scope:** Small (2 files)

---

## Task 03: Eksklusivitas tag & troli lintas delivery

**Description:** `delivery_scans` hanya unik per `(delivery_id, part_stock_id)`,
jadi satu tag FG bisa dipindai ke dua dokumen berbeda dan satu troli bisa
dipakai dua truk. Tolak tag/troli yang sudah diklaim dokumen lain yang belum
`released`.

**Acceptance criteria:**
- [ ] `resolveScan` menolak tag yang sudah ada di `delivery_scans` dokumen lain dengan status selain `released`, dengan pesan yang menyebut nomor DO pengklaim.
- [ ] Troli yang sudah dipakai dokumen lain (belum `released`) ditolak dengan pesan serupa.
- [ ] Test: scan tag yang sama ke DO-A lalu DO-B → DO-B ditolak.

**Verification:**
- [ ] `vendor/bin/pest tests/Feature/DeliveryTest.php tests/Feature/Api/DeliveryLoadingApiTest.php`
- [ ] `vendor/bin/pint --dirty --format agent`

**Dependencies:** Task 01

**Files likely touched:**
- `app/Services/DeliveryService.php`
- `tests/Feature/DeliveryTest.php`

**Estimated scope:** Small (2 files)

---

## Checkpoint 1: Integritas Stok
- [x] Semua test Phase 1 hijau (26 test di `DeliveryTest`, termasuk 5 baru)
- [x] `vendor/bin/pest` (suite penuh) hijau — 631 passed
- [x] Tidak ada regresi di `PickingListTest` (potong stok sekali, return pulih)
- [ ] Review manusia: baca diff `DeliveryService` sebelum lanjut

---

# Phase 2: Alur yang Putus

## Task 04: Perbaiki route Production Result

**Description:** `Result/Create.vue` memanggil `route('work-orders.results.store')`
dan `route('work-orders.results.destroy')` — keduanya tidak ada. Nama sebenarnya
`production-results.store` dan `production-results.destroy`. Akibatnya tombol
Laporkan dan Hapus tidak berfungsi, sehingga seluruh langkah pelaporan WIP/FG
dari web mati.

**Acceptance criteria:**
- [x] Tombol "Laporkan" mengirim ke `production-results.store` dan tersimpan.
- [x] Tombol hapus hasil mengirim ke `production-results.destroy` dan baris hilang.
- [x] Test HTTP: POST hasil produksi dari web mengembalikan redirect sukses dan menambah `production_results`.

**Verification:**
- [x] `vendor/bin/pest tests/Feature/ProductionResultTest.php` (atau test terkait)
- [x] `npm run build`

**Dependencies:** None (bisa paralel dengan Phase 1)

**Files likely touched:**
- `resources/js/Pages/Production/Result/Create.vue`
- `tests/Feature/*ProductionResult*.php`

**Estimated scope:** XS (1-2 files)

---

## Task 05: Sembunyikan release WO di web + jelaskan jalurnya

**Description:** Tombol "Release" di `WorkOrder/Show.vue` memanggil
`WoService::releaseWorkOrder`, yang hanya membuat booking tanpa
`material_issues`. Tanpa material issue tidak ada `production_material_receipts`,
sehingga `ProductionResultService` selalu menolak dengan "Material belum diterima
di mesin" — WO `in_progress` yang tidak bisa dilaporkan.

**Acceptance criteria:**
- [ ] Tombol release di web tidak lagi tampil untuk WO `planned`.
- [ ] WO Show menampilkan instruksi jelas: release & issue material dilakukan dari APK (scan tag), beserta tautan ke panduan.
- [ ] Route `work-orders.release` tetap ada tetapi mengembalikan pesan error yang menjelaskan jalur yang benar (bukan 500), atau dihapus beserta test-nya.
- [ ] Test: memanggil route release dari web tidak menghasilkan WO `in_progress` tanpa material issue.

**Verification:**
- [ ] `vendor/bin/pest tests/Feature/WorkOrderHttpTest.php tests/Feature/WorkOrderAllocationTest.php`
- [ ] `npm run build`

**Dependencies:** None

**Files likely touched:**
- `resources/js/Pages/Production/WorkOrder/Show.vue`
- `app/Http/Controllers/WorkOrderController.php` (atau route)
- `resources/js/i18n/catalogs/production.ts`
- `tests/Feature/WorkOrderHttpTest.php`

**Estimated scope:** Small (3-4 files)

**Catatan:** Bila Anda memilih opsi "web release membuat material_issues",
scope naik jadi Medium dan task ini perlu dipecah.

---

## Task 06: Render panel OQC di web + permission role qc

**Description:** `Delivery/Show.vue` sudah punya `passQc()`, `rejectQc()`,
`qcNotes`, `showReject` tetapi tidak satu pun dirender. APK butuh
`delivery.update`, sedangkan role `qc` hanya punya `delivery.view` — sehingga QC
tidak bisa melakukan OQC di mana pun. Tanpa APK, pengiriman berhenti permanen di
`picked`.

**Acceptance criteria:**
- [ ] Panel OQC tampil di Delivery Show pada status `picked`: textarea catatan + tombol "QC Lolos" dan "QC Tolak".
- [ ] QC tolak mewajibkan catatan (validasi klien + server).
- [ ] Role `qc` bisa melakukan OQC; role tanpa izin tidak melihat tombolnya.
- [ ] Test: role qc POST `deliveries.qc.pass` → 302; role lain → 403.

**Verification:**
- [ ] `vendor/bin/pest tests/Feature/DeliveryTest.php`
- [ ] `npm run build`

**Dependencies:** None

**Files likely touched:**
- `resources/js/Pages/Outgoing/Delivery/Show.vue`
- `app/Policies/DeliveryPolicy.php`
- `database/seeders/RolesAndPermissionsSeeder.php`
- `tests/Feature/DeliveryTest.php`

**Estimated scope:** Medium (4 files)

**Open question:** permission baru `delivery.oqc` (bersih) vs `delivery.update`
untuk qc (cepat). Plan ini default ke permission baru.

---

## Task 07: Pintu masuk Local PO

**Description:** Route, controller, dan tiga halaman Local PO lengkap, tetapi
nol tautan di seluruh UI — hanya bisa dibuka dengan mengetik `/local-pos`.
Katalog `incoming.localTile` sudah ada tetapi tidak dipakai.

**Acceptance criteria:**
- [ ] Tile "Local PO" muncul di launcher Incoming dengan permission yang benar.
- [ ] Purchase Order Index punya tautan ke Local PO.
- [ ] Test: `GET /incoming-data` merender tile Local PO untuk user berizin.

**Verification:**
- [ ] `vendor/bin/pest tests/Feature/` (test Incoming/LocalPo terkait)
- [ ] `npm run build`

**Dependencies:** None

**Files likely touched:**
- `resources/js/Pages/Incoming/ModuleLauncher.vue`
- `resources/js/Pages/Incoming/PurchaseOrder/Index.vue`
- `tests/Feature/LocalPoTest.php` (bila ada)

**Estimated scope:** Small (2-3 files)

---

## Task 08: Container inspection UI

**Description:** Route `incoming-containers.inspect` dan
`IncomingArrivalController::inspectContainer` ada, tetapi tidak dipanggil
komponen Vue mana pun. Langkah "Arrival + container inspection" mustahil diisi
dari UI; statusnya tertulis "belum diperiksa" selamanya.

**Acceptance criteria:**
- [ ] Arrival Show punya aksi per container untuk mencatat hasil inspeksi (status, kondisi seal, catatan).
- [ ] Tombol hanya tampil untuk user berizin; setelah simpan, status container berubah di tabel.
- [ ] Test: POST inspect menyimpan `incoming_arrival_container_inspections` dan mengubah status.

**Verification:**
- [ ] `vendor/bin/pest tests/Feature/` (test Arrival/container terkait)
- [ ] `npm run build`

**Dependencies:** None

**Files likely touched:**
- `resources/js/Pages/Incoming/Arrival/Show.vue`
- `resources/js/i18n/catalogs/incoming.ts`
- `tests/Feature/*Arrival*.php`

**Estimated scope:** Medium (3-4 files)

---

## Task 09: Buka akses Outgoing untuk PPIC

**Description:** `Launcher.vue:57` menyembunyikan modul Outgoing kecuali user
punya `stock.issue`. Role `ppic` punya `delivery.view/create/update/delete` tetapi
tidak punya `stock.issue`, sehingga Picking List dan Delivery tidak bisa dibuka
PPIC — padahal itu pekerjaannya.

**Acceptance criteria:**
- [ ] Gate launcher Outgoing memakai `['stock.issue', 'delivery.view', 'delivery.create']`.
- [ ] PPIC bisa membuka launcher Outgoing, Picking List, dan Delivery.
- [ ] User tanpa izin mana pun tetap tidak melihat tile.
- [ ] Test: login sebagai ppic → `GET /outgoing` merender 3 tile.

**Verification:**
- [ ] `vendor/bin/pest tests/Feature/` (test launcher/permission)
- [ ] `npm run build`

**Dependencies:** None

**Files likely touched:**
- `resources/js/Pages/Launcher.vue`
- `tests/Feature/RolePermissionTest.php`

**Estimated scope:** Small (2 files)

---

## Checkpoint 2: Alur
- [ ] Satu siklus penuh bisa diselesaikan dari UI + APK
- [ ] `vendor/bin/pest` dan `npm run build` hijau
- [ ] Review manusia: jalankan alur di browser (bukan hanya test)

---

# Phase 3: Keamanan & Operasional

## Task 10: Gate di 5 controller + test matriks permission

**Description:** `MachineController`, `ProcessController`, `UomController` sama
sekali tidak memanggil `Gate::authorize`; `ConfigMasterController` dan
`SupplierController` hanya di `index`. Akibatnya akun `qc`/`production` bisa
menghapus mesin dan UOM — merusak BOM, cycle time, dan label mesin.

**Acceptance criteria:**
- [ ] Setiap action mutasi di 5 controller memanggil `Gate::authorize` dengan permission modul yang sesuai.
- [ ] Permission `config.*`, `machine.*`, `process.*`, `uom.*`, `supplier.*` sudah ada di seeder dan diberikan ke role yang tepat.
- [ ] Test matriks: role `qc` dan `production` ditolak (403) pada create/update/delete kelima modul; `super-admin` diizinkan.

**Verification:**
- [ ] `vendor/bin/pest tests/Feature/RolePermissionTest.php`
- [ ] `vendor/bin/pint --dirty --format agent`

**Dependencies:** None

**Files likely touched:**
- `app/Http/Controllers/{ConfigMaster,Machine,Process,Uom,Supplier}Controller.php`
- `app/Policies/*` (bila perlu policy baru)
- `database/seeders/RolesAndPermissionsSeeder.php`
- `tests/Feature/RolePermissionTest.php`

**Estimated scope:** Medium (5+ files — boleh dipecah per controller bila perlu)

---

## Task 11: Kredensial seed dari environment

**Description:** `RolesAndPermissionsSeeder` membuat `admin@geumcheon.local` /
`password` plus 8 user demo dengan password `password`, dan
`ProductionOperatorSeeder` memakai `gci2026`. `DatabaseSeeder` menjalankan
keduanya dan `AGENTS.md` mendokumentasikannya sebagai perintah deploy — jadi
kredensial publik ini ikut ke server trial.

**Acceptance criteria:**
- [ ] Password admin diambil dari env (`SEED_ADMIN_PASSWORD`), fallback `password` hanya bila `APP_ENV=local`.
- [ ] User demo per role tidak dibuat saat `APP_ENV=production`.
- [ ] Password operator produksi dari env, dengan fallback terdokumentasi.
- [ ] Seeder tetap idempoten (`updateOrCreate`) dan test suite tetap hijau.
- [ ] `AGENTS.md` diperbarui: cara mengisi password seed.

**Verification:**
- [ ] `vendor/bin/pest` (suite penuh — seeder dipakai banyak test)
- [ ] `vendor/bin/pint --dirty --format agent`

**Dependencies:** None

**Files likely touched:**
- `database/seeders/RolesAndPermissionsSeeder.php`
- `database/seeders/ProductionOperatorSeeder.php`
- `.env.example`
- `AGENTS.md`

**Estimated scope:** Small (4 files)

---

## Task 12: Guard `erp:reset-data` (environment + audit)

**Description:** `erp:reset-data --force` langsung melakukan `TRUNCATE` tanpa
memeriksa `APP_ENV`, tanpa maintenance mode, dan tanpa mencatat siapa pelakunya.
Di server produksi, satu perintah salah menghapus seluruh transaksi tanpa jejak.

**Acceptance criteria:**
- [ ] Perintah menolak berjalan bila `app()->isProduction()` kecuali ada flag eksplisit tambahan (mis. `--i-know`).
- [ ] Pelaku dicatat: user OS (`$SUDO_USER`/`posix_getpwuid`) + hostname + timestamp ke log persisten.
- [ ] `scripts/reset-trial.sh` mengaktifkan maintenance mode sebelum truncate dan mematikannya setelah verifikasi.
- [ ] Skrip menolak lanjut bila `config('database.connections.pgsql.database')` ≠ `DB_DATABASE` di `.env` (mencegah backup DB A lalu truncate DB B).
- [ ] Test: perintah menolak di environment production tanpa flag eksplisit.

**Verification:**
- [ ] `vendor/bin/pest tests/Feature/ResetTransactionalDataTest.php`
- [ ] `bash -n scripts/reset-trial.sh`
- [ ] `vendor/bin/pint --dirty --format agent`

**Dependencies:** None

**Files likely touched:**
- `app/Console/Commands/ResetTransactionalData.php`
- `scripts/reset-trial.sh`
- `tests/Feature/ResetTransactionalDataTest.php`

**Estimated scope:** Medium (3 files)

---

## Checkpoint 3: Keamanan
- [ ] Test matriks membuktikan role non-admin ditolak di semua mutasi master
- [ ] Reset menolak berjalan di production tanpa flag eksplisit
- [ ] Review manusia sebelum deploy trial

---

# Phase 4: Integritas Data Lanjutan

## Task 13: Unique `(part_id, tag)` + guard hapus dokumen hulu

**Description:** `part_stocks` hanya punya unique pada `id`, sehingga dua
receive paralel bisa membuat dua baris untuk tag yang sama dan `postStock`
menggabungkan qty dari receive berbeda (invoice/supplier jadi salah). Selain itu
`IncomingArrivalController::destroy` dan `LocalPoController::destroy` menghapus
dokumen tanpa memeriksa receive, menyisakan stok yatim.

**Acceptance criteria:**
- [ ] Cek duplikat `(part_id, tag)` dulu; bila ada, tangani manual sebelum constraint.
- [ ] Partial unique `(part_id, tag) WHERE deleted_at IS NULL` terpasang.
- [ ] Hapus Arrival/Local PO yang sudah punya receive ditolak dengan pesan jelas (bukan menghapus header lalu menyisakan stok).
- [ ] Test: hapus arrival berisi receive → 302 dengan error, data utuh.

**Verification:**
- [ ] `vendor/bin/pest tests/Feature/` (Arrival, LocalPo, Receive)
- [ ] `vendor/bin/pint --dirty --format agent`

**Dependencies:** None

**Files likely touched:**
- `database/migrations/*_add_unique_part_tag_to_part_stocks.php`
- `app/Http/Controllers/IncomingArrivalController.php`
- `app/Http/Controllers/LocalPoController.php`
- `tests/Feature/*Arrival*.php`

**Estimated scope:** Medium (4 files)

---

## Task 14: Generator nomor atomik

**Description:** `WorkOrder::generateWoNo`, `Delivery::generateDeliveryNo`,
`PickingList::generatePickingNo`, `MaterialIssue`, dan `IncomingArrival` memakai
pola `max/LIKE` + insert tanpa lock atau retry. Dua user membuat dokumen
bersamaan → unique violation → 500 ke APK. Hanya BOM yang punya retry loop.

**Acceptance criteria:**
- [ ] Semua generator menangkap SQLSTATE `23505` dan mencoba ulang (maks 3×), mengikuti pola `BomController::withBomNoRetry`.
- [ ] Nomor tetap tidak dipakai ulang setelah dokumen dihapus (`withTrashed` dipertahankan).
- [ ] Test: dua pemanggilan berurutan dalam transaksi berbeda menghasilkan nomor berbeda dan tidak melempar exception.

**Verification:**
- [ ] `vendor/bin/pest` (test generator yang ada)
- [ ] `vendor/bin/pint --dirty --format agent`

**Dependencies:** None

**Files likely touched:**
- `app/Models/WorkOrder.php`
- `app/Models/Delivery.php`
- `app/Models/PickingList.php`
- `app/Models/MaterialIssue.php`
- `app/Models/IncomingArrival.php`

**Estimated scope:** Medium (5 files)

---

## Checkpoint 4: Data
- [ ] Unique constraint terpasang dan tidak ada duplikat tersisa
- [ ] Generator tidak lagi melempar 500 pada tabrakan
- [ ] `vendor/bin/pest` hijau

---

# Phase 5: UX, i18n, Performa

## Task 15: 10 key i18n hilang + perluas `check-i18n.mjs`

**Description:** Vue-i18n merender path mentah bila key tidak ada. Contoh nyata:
label form membaca `production.notes`, filter status Delivery punya opsi kosong,
`aria-label` kosong. `scripts/check-i18n.mjs` hanya memvalidasi format pesan,
tidak mendeteksi key yang hilang.

**Acceptance criteria:**
- [ ] Semua key yang dilaporkan audit ada di ketiga bahasa: `production.notes`, `production.receiptDate`, `production.part`, `outgoing.allStatuses`, `outgoing.step_draft`, `outgoing.posted`, `production.stockTags*`, `production.noTagsAnyPart`, `incoming.arrivalDate`, `incoming.truckNoRequired`, `common.close`, `production.statuses.planned`.
- [ ] `scripts/check-i18n.mjs` melaporkan `t('ns.key')` yang tidak ada di katalog (gagal dengan exit code ≠ 0).
- [ ] `npm run build` lulus guard yang diperluas tanpa error.

**Verification:**
- [ ] `node scripts/check-i18n.mjs`
- [ ] `npm run build`

**Dependencies:** None

**Files likely touched:**
- `resources/js/i18n/catalogs/{production,outgoing,incoming,common}.ts`
- `scripts/check-i18n.mjs`

**Estimated scope:** Medium (5 files)

---

## Task 16: `CustomerPrice` berhenti menghapus input

**Description:** `watch(() => props.parts)` di `CustomerPrice/Index.vue` membangun
ulang `form.prices` setiap props berubah. Setiap POST gagal atau pengetikan di
kotak pencarian (debounce 250 ms) menghapus semua harga yang sedang diketik —
data loss senyap di layar 100+ baris.

**Acceptance criteria:**
- [ ] Mengetik harga lalu memicu pencarian/filter tidak menghapus harga yang belum disimpan.
- [ ] Ganti customer tetap memuat ulang harga dari server (perilaku yang diinginkan).
- [ ] Submit gagal (validasi) tidak mengosongkan input.
- [ ] Test (bila memungkinkan) atau manual check terdokumentasi di PR.

**Verification:**
- [ ] `npm run build`
- [ ] `vendor/bin/pest tests/Feature/CustomerPriceTest.php`
- [ ] Manual check: ketik 3 harga → ubah kotak pencarian → harga tetap

**Dependencies:** None

**Files likely touched:**
- `resources/js/Pages/Master/CustomerPrice/Index.vue`

**Estimated scope:** Small (1 file)

---

## Task 17: Index tag + `output_part_stock_id` + `whereDate` → range

**Description:** Lima jalur scan memakai `LOWER(COALESCE(tag,'')) = ?` sehingga
index btree `part_stocks_tag_index` tidak terpakai. `production_results.output_part_stock_id`
tidak punya index dan tidak punya FK, padahal dipakai di dalam loop dan 5 join
laporan. `whereDate()` pada kolom `timestamp` memaksa seq scan.

**Acceptance criteria:**
- [ ] Query tag memakai index (EXPLAIN menunjukkan Index Scan, bukan Seq Scan) — lewat expression index yang identik dengan query, atau query diubah ke `tag = UPPER(BTRIM(?))`.
- [ ] Index parsial pada `production_results(output_part_stock_id)` terpasang.
- [ ] `whereDate()` pada kolom timestamp di `WipInventoryService` dan `DeliveryService` diganti rentang `>= d 00:00 AND < d+1 00:00`.
- [ ] Laporan WIP dan kartu stok tetap menghasilkan angka yang sama setelah perubahan.

**Verification:**
- [ ] `vendor/bin/pest tests/Feature/WipInventoryTest.php tests/Feature/DeliveryTest.php`
- [ ] `EXPLAIN (ANALYZE, BUFFERS)` untuk query tag menunjukkan Index Scan

**Dependencies:** None

**Files likely touched:**
- `database/migrations/*_add_perf_indexes.php`
- `app/Services/WipInventoryService.php`
- `app/Services/DeliveryService.php`

**Estimated scope:** Medium (3-4 files)

---

## Checkpoint 5: Selesai
- [ ] Semua acceptance criteria terpenuhi
- [ ] `vendor/bin/pest`, `npm run build`, `vendor/bin/pint --dirty` hijau
- [ ] Siap deploy trial
- [ ] Review manusia sebelum `git push`

---

# Urutan Pengerjaan yang Disarankan

| Sesi | Task | Alasan |
|---|---|---|
| 1 | Task 01, 02 | Risiko stok tertinggi; fail fast |
| 2 | Task 03, 04 | Sisa integritas + 1 baris yang membuka alur pelaporan |
| 3 | Task 05, 09 | Dua perubahan kecil yang membuka pekerjaan PPIC & planner |
| 4 | Task 06, 07 | OQC + Local PO (dua blocker fungsional) |
| 5 | Task 08, 10 | Container inspection + keamanan |
| 6 | Task 11, 12 | Kredensial + guard reset (sebelum deploy) |
| 7 | Task 13, 14 | Integritas data lanjutan |
| 8 | Task 15, 16, 17 | UX/i18n/performa |

**Bisa paralel:** Task 04 (frontend) dengan Task 01-03 (backend) — file tidak bertabrakan.
**Harus berurutan:** Task 01 → 02 → 03 (satu file, `DeliveryService`).
