# Task List: Material Tracker Clarity

Capability map: `docs/prd/material-tracker-clarity.md`
Plan: `tasks/plan.md`

PRD modul:

- `docs/prd/daily-planned-wo-feed.md` (modul 1)
- `docs/prd/material-scan-context.md` (modul 2)
- `docs/prd/tracker-dashboard.md` (modul 3)

## Task 1: API — saring WO berdasarkan jadwal hari ini dan kirim qty hari itu

**Description:** `GET /api/work-orders` hanya mengembalikan WO yang punya jadwal
pada tanggal berjalan. Tanggal ditentukan server memakai zona waktu pabrik (WIB),
bukan jam perangkat. Kolom jadwal yang dibaca bergeser sesuai jarak tanggal plan:
`target_d` pada `plan_date`, `target_d1` sehari setelahnya, `target_d2` dua hari
setelahnya. Qty hari itu diambil dari nilai terbesar antar baris mesin WO. Respons
menambah `plan_date`, `planned_qty`, `issued_qty`, dan `remaining_qty`.

**Acceptance criteria:**
- [x] WO dengan jadwal hari itu lebih dari nol muncul; jadwal kosong atau nol tidak muncul.
- [x] Kolom jadwal berpindah sesuai jarak tanggal plan, dan WO tidak muncul setelah melewati rentangnya.
- [x] `planned_qty` memakai nilai terbesar antar baris mesin, bukan penjumlahan.
- [x] `issued_qty` mengikuti dokumen issue material; `remaining_qty` = `planned_qty` − `issued_qty`, minimum nol.
- [x] Tanggal dihitung memakai zona waktu pabrik, bukan UTC atau jam perangkat.
- [x] Hasil diurutkan alur routing step pertama lalu nomor WO; pencarian mencocokkan nomor WO dan nomor part FG.
- [x] WO `completed` dan `cancelled` tidak muncul.
- [x] Field respons lama tetap terkirim; otorisasi `viewAny` tetap berlaku.

**Verification:**
- [x] Tests pass: `php artisan test --compact tests/Feature/Api/MaterialIssueApiTest.php` (32 test, 132 assertions)
- [x] Lint: `vendor/bin/pint --dirty --format agent`
- [x] Full suite: `php artisan test --compact` (162 test, 991 assertions)
- [x] Manual check: digantikan test per tanggal plan, sehari sesudahnya, dua hari sesudahnya, dan di luar rentang

**Catatan implementasi:**
- Zona waktu pabrik dibaca dari `ConfigMaster::getValue('SYSTEM', 'timezone', 'Asia/Jakarta')`, mengikuti pola `HandleInertiaRequests`.
- Dokumen issue berstatus `cancelled` tidak dihitung sebagai material keluar.
- Default `per_page` dinaikkan 20 → 200 (batas 500) agar daftar hari itu tidak terpotong; paginasi dipertahankan.
- Parameter `status` kini hanya mempersempit daftar (tidak bisa membuka `completed`/`cancelled`).

**Dependencies:** None

**Files likely touched:**
- `app/Http/Controllers/Api/MaterialIssueApiController.php`
- `tests/Feature/Api/MaterialIssueApiTest.php`

**Estimated scope:** Medium: 2 files plus tests

## Task 2: API — daftar WO menunggu dijadwalkan

**Description:** Menambahkan `meta.waiting_for_plan` (jumlah) dan
`meta.waiting_work_orders` (daftar `{id, wo_no, part}`) pada respons
`GET /api/work-orders`, sehingga APK bisa menampilkan WO mana yang menunggu
dijadwalkan alih-alih hanya jumlahnya.

**Acceptance criteria:**
- [x] `waiting_for_plan` menghitung WO `in_progress` yang punya baris plan tetapi tidak punya jadwal hari itu.
- [x] `waiting_work_orders` memuat `id`, `wo_no`, dan part FG.
- [x] WO yang sudah tampil di daftar utama tidak ikut terhitung di daftar menunggu.
- [x] WO tanpa baris plan sama sekali tidak ikut terhitung.
- [x] Otorisasi `viewAny` tetap berlaku.

**Verification:**
- [x] Tests pass: `php artisan test --compact tests/Feature/Api/MaterialIssueApiTest.php` (37 test, 150 assertions)
- [x] Lint: `vendor/bin/pint --dirty --format agent`
- [x] Full suite: `php artisan test --compact` (167 test, 1009 assertions)
- [x] Manual check: digantikan test untuk WO terjadwal, tanpa baris plan, dan belum release

**Catatan implementasi:**
- Daftar menunggu memakai `whereExists` baris plan + `whereNotIn` himpunan terjadwal hari ini.
- Daftar sengaja tidak dipotong agar konsisten dengan aturan "jangan sembunyikan pekerjaan".
- Perlu dikonfirmasi: WO yang plan-nya bertanggal masa depan atau sudah kedaluwarsa
  ikut terhitung sebagai "menunggu dijadwalkan", sesuai bacaan harfiah PRD.

**Dependencies:** Task 1

**Files likely touched:**
- `app/Http/Controllers/Api/MaterialIssueApiController.php`
- `tests/Feature/Api/MaterialIssueApiTest.php`

**Estimated scope:** Small: 2 files plus tests

## Task 3: API — `materials[]` material berstok pada release-context

**Description:** `GET /api/work-orders/{workOrder}/release-context` menambah
`stock_state`, `size`, `issued_qty`, dan `materials[]`. Material diambil dari
alokasi planner; bila belum ada alokasi, dari substitute aktif yang berstok. Main
part BOM tidak pernah muncul. Item tanpa material berstok menghasilkan
`stock_state: none` dan tetap memuat daftar material sah.

**Acceptance criteria:**
- [x] Item dengan alokasi menampilkan part alokasi, bukan main part BOM.
- [x] Main part BOM tidak pernah muncul di `materials`.
- [x] Item tanpa alokasi menampilkan substitute aktif yang berstok.
- [x] Item tanpa material berstok menghasilkan `stock_state` bernilai `none`.
- [x] `allocation_qty` terkirim untuk material hasil alokasi.
- [x] `size` memakai `parts.size`, dengan cadangan `work_order_items.size` bila kosong.
- [x] `issued_qty` terkirim per item dan per material.
- [x] Field respons lama (`part`, `allowed_parts`, `recommended_tags`, `required`, `consumed`, `remaining`) tetap terkirim.
- [x] Query memakai batch sehingga tidak menimbulkan N+1.

**Verification:**
- [x] Tests pass: `php artisan test --compact tests/Feature/Api/MaterialIssueApiTest.php` (46 test, 218 assertions)
- [x] Lint: `vendor/bin/pint --dirty --format agent`
- [x] Full suite: `php artisan test --compact` (176 test, 1077 assertions)
- [x] Manual check: digantikan test untuk alokasi, tanpa alokasi, dan tanpa stok
- [ ] N+1: diverifikasi lewat review kode (semua query batch di luar loop item), belum ada test jumlah query

**Catatan implementasi:**
- Urutan `materials`: part alokasi (qty terbesar dulu), lalu substitute berstok.
- Bila belum ada alokasi dan tidak ada substitute berstok, daftar material sah tetap
  dikirim dengan `stock_state: none` agar operator tahu harus melapor.
- `size` item mengikuti material pertama, dengan cadangan `work_order_items.size`.
- `tags[]` belum memuat invoice/supplier dan urutan FIFO berbasis `invoice_date` —
  itu lingkup Task 4.
- Controller kini 648 baris (dari 435). Blok pembentukan material di
  `releaseContext` kandidat untuk diekstrak ke kelas tersendiri.

**Dependencies:** None

**Files likely touched:**
- `app/Http/Controllers/Api/MaterialIssueApiController.php`
- `tests/Feature/Api/MaterialIssueApiTest.php`

**Estimated scope:** Medium: 2 files plus tests

## Task 4: API — tag lengkap (invoice, supplier) dan urut FIFO

**Description:** Setiap tag pada `materials[].tags[]` memuat `tag`, `invoice`,
`supplier`, `qty`, `uom`, `received_at`, `part_id`, dan `part_number`. Urutan
memakai `received_at` naik, lalu `invoice_date` naik, lalu `id`. Seluruh tag
terkirim tanpa dipotong empat, dan `qty` sudah dikurangi booking aktif.

**Acceptance criteria:**
- [x] Setiap tag memuat `invoice` dan `supplier`.
- [x] Urutan tag: `received_at` naik, lalu `invoice_date`, lalu `id`.
- [x] Seluruh tag tersedia terkirim tanpa dipotong.
- [x] `qty` pada tag sudah dikurangi booking aktif dan tag tanpa sisa tidak dikirim.
- [x] Tag tanpa invoice tetap terkirim dengan `invoice` bernilai kosong.

**Verification:**
- [x] Tests pass: `php artisan test --compact tests/Feature/Api/MaterialIssueApiTest.php` (53 test, 266 assertions)
- [x] Lint: `vendor/bin/pint --dirty --format agent`
- [x] Full suite: `php artisan test --compact` (183 test, 1125 assertions)
- [x] Manual check: digantikan test rantai receive → arrival → supplier, pemecah seri invoice_date, dan tag tanpa invoice

**Catatan implementasi:**
- Invoice/supplier diambil lewat relasi yang sama dengan `bookFromTag` dan halaman web,
  sehingga yang tampil sama dengan yang tersimpan saat release.
- Urutan FIFO dipindah dari SQL ke PHP (`sortBy`) karena `invoice_date` berada di
  tabel arrival; relasi dipakai agar soft-delete tetap dihormati.
- Tiga test (tanpa potongan, qty dikurangi booking, tag tanpa sisa) lulus sejak awal —
  ketiganya penjaga regresi, bukan pembuktian perubahan.
- Efek samping: pemecah seri `recommended_tags` (field lama) kini memakai
  `invoice_date` sebelum `id`.

**Dependencies:** Task 3

**Files likely touched:**
- `app/Http/Controllers/Api/MaterialIssueApiController.php`
- `tests/Feature/Api/MaterialIssueApiTest.php`

**Estimated scope:** Small: 2 files plus tests

## Task 3b: Ekstrak konteks material ke service (refactor, di luar plan awal)

**Description:** Memindahkan pembentukan konteks material dari
`MaterialIssueApiController::releaseContext` ke `App\Services\ReleaseContextService`
agar controller tidak terus membengkak sebelum Task 4 menambah invoice/supplier.

**Acceptance criteria:**
- [x] Perilaku tidak berubah — seluruh test lama tetap lulus tanpa penyesuaian.
- [x] Controller menyusut dari 648 menjadi 447 baris.
- [x] Import yang tidak lagi terpakai dibuang.

**Verification:**
- [x] `php artisan test --compact` → 176 test, 1077 assertions lulus (sama seperti sebelum refactor)
- [x] Lint: `vendor/bin/pint --dirty --format agent`

**Catatan:** refactor ini murni; bug yang ditemukan di dalamnya diperbaiki terpisah di Task 3c.

## Task 3c: Perbaiki `part_number` pada `recommended_tags` (bug lama)

**Description:** Closure pembentuk `recommended_tags` memakai `$parts` tanpa
menangkapnya lewat `use`, sehingga `part_number` selalu `null`. Operator tidak bisa
mencocokkan tag rekomendasi dengan nomor part.

**Acceptance criteria:**
- [x] `recommended_tags[].part_number` terisi nomor part yang benar.
- [x] Ada test yang gagal sebelum perbaikan dan lulus sesudahnya.

**Verification:**
- [x] RED: `Failed asserting that null is identical to 'KBTGICBVT12'`
- [x] GREEN: `php artisan test --compact tests/Feature/Api/MaterialIssueApiTest.php` → 47 test, 225 assertions
- [x] Full suite: 177 test, 1084 assertions lulus
- [x] Lint: `vendor/bin/pint --dirty --format agent`

## Checkpoint: Kontrak API

- [ ] `php artisan test --compact tests/Feature/Api/MaterialIssueApiTest.php` lulus
- [ ] `vendor/bin/pint --dirty --format agent` lulus
- [ ] Respons sesuai PRD modul 1 dan 2
- [ ] Field respons lama masih ada
- [ ] Review dengan manusia sebelum menyentuh APK

## Task 5: APK — daftar WO harian (Rencana D, Ter-issue, sisa)

**Description:** Model `WorkOrderSummary` menerima `plan_date`, `planned_qty`,
`issued_qty`, dan `remaining_qty`. Repository berhenti memaksa
`status=planned` sehingga WO `in_progress` ikut tampil. Layar daftar menampilkan
"Rencana D" dan "Ter-issue" sejajar, sisa ditonjolkan dengan teks atau ikon, dan
tiga kondisi kosong dibedakan.

**Acceptance criteria:**
- [x] Model memetakan `plan_date`, `planned_qty`, `issued_qty`, `remaining_qty`.
- [x] Permintaan daftar tidak lagi memaksa `status=planned`.
- [x] Kartu WO menampilkan "Rencana D" dan "Ter-issue" sebagai dua angka terpisah.
- [x] Sisa ditandai dengan teks atau ikon, bukan hanya warna.
- [x] Kondisi kosong dibedakan: tidak ada pekerjaan hari ini, ada WO menunggu, dan gagal memuat.
- [x] Kondisi gagal memuat menyediakan tombol coba lagi.
- [x] Seluruh teks baru tersedia dalam tiga bahasa (id, en, ko).
- [x] `flutter analyze` bersih.

**Verification:**
- [x] Tests pass: `flutter test` (30 test) — termasuk 6 widget test layar daftar
- [x] Lint: `flutter analyze` — no issues
- [x] Suite ERP: `php artisan test --compact` (184 test, 1127 assertions)
- [x] Manual check: digantikan widget test untuk kartu terjadwal, tuntas, kurang, kosong, dan gagal muat

**Catatan implementasi:**
- `listWorkOrders` kini mengembalikan `WorkOrderFeed` (daftar + `waitingForPlan`), bukan `List<WorkOrderSummary>`.
- Field jadwal harian dibuat nullable karena `WorkOrderSummary` juga dipakai payload
  `release-context` yang tidak mengirimnya; kartu punya cabang cadangan.
- **Perluasan API di luar rencana Task 5:** PRD meminta kartu menampilkan model part FG,
  tetapi respons `/api/work-orders` belum mengirim `model`. Saya tambahkan field itu
  beserta test-nya.
- Test model ditulis lebih dulu (RED: error kompilasi), lalu implementasi.
  Widget test ditulis setelah UI jadi — karakterisasi, bukan RED.
- `workOrdersEmpty` kini tidak terpakai di layar daftar (diganti `noWorkToday`).

**Dependencies:** Task 1, Task 2

**Files likely touched:**
- `lib/models/work_order.dart`
- `lib/repositories/issue_repository.dart`
- `lib/features/work_orders/work_orders_screen.dart`
- `lib/core/l10n/app_localizations.dart`
- `test/models_test.dart`

**Estimated scope:** Medium: 5 files

## Task 6: APK — kartu item material berstok

**Description:** Model `ReleaseItem` menerima `stock_state`, `size`,
`issued_qty`, dan daftar `materials` beserta tag-nya. Kartu item menampilkan
material berstok (bukan main part BOM) dengan nomor part, nama, ukuran, dan
invoice serta supplier pada tag.

**Acceptance criteria:**
- [x] Model memetakan `stock_state`, `size`, `issued_qty`, dan `materials[]`.
- [x] Kartu item menampilkan material berstok beserta nomor part, nama, dan ukuran.
- [x] Main part BOM tidak pernah menjadi judul kartu.
- [x] Tag menampilkan invoice dan supplier.
- [x] Item dengan `stock_state` bernilai `none` menampilkan pesan belum ada material berstok.
- [x] Seluruh teks baru tersedia dalam tiga bahasa (id, en, ko).
- [x] `flutter analyze` bersih.

**Verification:**
- [x] Tests pass: `flutter test` (36 test) — termasuk 4 widget test layar issue
- [x] Lint: `flutter analyze` — no issues
- [x] Manual check: digantikan widget test untuk judul material, ukuran, invoice/supplier, dan kondisi tanpa stok

**Catatan implementasi:**
- Kelas model dinamai `MaterialStock`, bukan `Material`, karena `Material` bentrok
  dengan widget Flutter (`ambiguous_import`).
- Chips `recommended_tags` lama dihapus dari kartu; digantikan daftar tag per material
  yang sudah memuat invoice dan supplier. Field `recommendedTags` tetap diparsing
  (warisan).
- Helper format angka diekstrak ke `lib/core/utils/format.dart` agar tidak duplikat
  dengan layar daftar WO.
- Saat satu material, label material hanya muncul sekali (judul kartu); header blok
  disembunyikan agar tidak mubazir.
- Task 8 akan menambah progres per material, empat angka, dan melipat daftar tag.

**Dependencies:** Task 3, Task 4

**Files likely touched:**
- `lib/models/release_context.dart`
- `lib/features/issue/issue_screen.dart`
- `lib/core/l10n/app_localizations.dart`
- `test/models_test.dart`

**Estimated scope:** Medium: 4 files

## Checkpoint: APK Modul 1 dan 2

- [ ] `flutter analyze` dan `flutter test` lulus
- [ ] Operator melihat WO hari itu beserta subspart berstok
- [ ] Main part BOM tidak muncul sebagai material
- [ ] Review dengan manusia sebelum menyusun dashboard

## Task 7: APK — ringkasan, daftar menunggu, dan kartu WO — DIBATALKAN

**Dibatalkan 2026-09-25.** Daftar tingkat atas berubah menjadi berbasis material
(permintaan Pak Arnold), sehingga struktur yang dibangun task ini akan langsung
dibongkar. Digantikan **Task 12**. Kartu WO yang sudah selesai di Task 5 tetap
dipakai sebagai isi layar DETAILS. Model `WaitingWorkOrder` dan `plannedQtyTotal`
yang sempat dibuat tetap dipakai.

## Task 10: API — papan material harian (D dan D+1)

**Description:** Endpoint baru `GET /api/material-board` yang mengembalikan satu
baris per pasangan (material, satuan) berisi qty kebutuhan untuk tanggal berjalan
dan besoknya. Material adalah material acuan BOM dari item leaf. Qty kebutuhan
dihitung sebagai `qty_required × (planned_qty ÷ qty WO)` dijumlahkan atas seluruh
WO yang dijadwalkan tanggal itu.

**Acceptance criteria:**
- [x] Satu baris per pasangan (material, satuan), bukan per WO.
- [x] Baris memuat nama material, model, ukuran, dan satuan.
- [x] `day_qty` memakai aturan jadwal tanggal berjalan.
- [x] `next_day_qty` memakai aturan jadwal tanggal besok.
- [x] Qty memakai rasio porsi hari, bukan qty WO penuh.
- [x] WO dengan qty nol dilewati tanpa error pembagian.
- [x] Satu material dari beberapa WO dijumlahkan menjadi satu baris.
- [x] Material dengan kebutuhan tuntas tidak muncul.
- [x] Material tanpa WO terjadwal tidak muncul.
- [x] Material Subcon tetap muncul, mengikuti aturan leaf alur release
- [x] D+2 tidak muncul.
- [x] Otorisasi tetap berlaku; query memakai batch tanpa N+1.

**Verification:**
- [x] Tests pass: `php artisan test --compact tests/Feature/Api/MaterialBoardApiTest.php` (16 test, 63 assertions)
- [x] Lint: `vendor/bin/pint --dirty --format agent`
- [x] Full suite: `php artisan test --compact` (200 test, 1190 assertions)
- [x] Manual check: digantikan test untuk rasio porsi hari, D+1, dua WO, dan dokumen issue batal

**Catatan implementasi:**
- Logika jadwal harian diekstrak ke `App\Services\DailyScheduleService` agar papan
  material tidak menduplikasi SQL yang sama; controller lama ikut memakainya.
- Penghitung "WO menunggu dijadwalkan" dibawa ke respons papan (`waiting_for_plan`
  dan `waiting_work_orders`) supaya WO yang sudah release tapi belum dijadwalkan
  tidak hilang dari pandangan setelah daftar berbasis WO digantikan.
- `day_issued_qty` dihitung dari dokumen issue yang `issue_date`-nya sama dengan
  tanggal papan, dipetakan lewat item WO ke material acuan BOM-nya.
- **Koreksi PRD:** klaim "Subcon tidak masuk papan" pada draf awal salah dan sudah
  diperbaiki. `bom_items` memuat 22 baris Subcon, dan alur release tetap
  membooking materialnya — jadi material Subcon memang dihitung.

**Dependencies:** Task 1

**Files likely touched:**
- `app/Http/Controllers/Api/MaterialBoardApiController.php`
- `app/Services/MaterialBoardService.php`
- `routes/api.php`
- `tests/Feature/Api/MaterialBoardApiTest.php`

**Estimated scope:** Medium: 4 files

## Task 11: API — DETAILS material (WO + subspart + invoice)

**Description:** Endpoint baru `GET /api/material-board/{part}/details?date=Y-m-d`
yang mengembalikan daftar WO di balik sebuah material pada satu tanggal, beserta
subspart yang tersedia lengkap dengan tag FIFO, invoice, dan supplier.

**Acceptance criteria:**
- [x] DETAILS memuat daftar WO dengan nomor WO, status, qty jadwal, kebutuhan material, sudah dikeluarkan, dan sisa.
- [x] Setiap WO memuat `id` sehingga APK dapat masuk ke alur scan.
- [x] DETAILS memuat subspart beserta stok tersedia.
- [x] Tag memuat invoice dan supplier serta urut FIFO.
- [x] Parameter `date` opsional dan default ke tanggal pabrik berjalan.
- [x] Tanggal di luar rentang jadwal menghasilkan daftar kosong, bukan error.
- [x] Otorisasi tetap berlaku.

**Verification:**
- [x] Tests pass: `php artisan test --compact tests/Feature/Api/MaterialBoardApiTest.php` (25 test, 109 assertions)
- [x] Lint: `vendor/bin/pint --dirty --format agent`
- [x] Full suite: `php artisan test --compact` (209 test, 1236 assertions)
- [x] Manual check: digantikan test untuk rasio porsi hari, dokumen issue, dan filter satuan

**Catatan implementasi:**
- Menambah parameter `uom` opsional pada DETAILS. Tidak ada di kontrak awal PRD,
  tetapi diperlukan karena satu material bisa diminta dalam satuan berbeda dan
  agregatnya tidak boleh dicampur. APK sebaiknya selalu mengirim `uom`.
- Logika stok+tag diekstrak ke `App\Services\PartStockTagService` dan dipakai
  bersama `ReleaseContextService`, sehingga urutan FIFO serta isi invoice/supplier
  dijamin sama di layar issue dan di DETAILS.

**Dependencies:** Task 10

**Files likely touched:**
- `app/Http/Controllers/Api/MaterialBoardApiController.php`
- `app/Services/MaterialBoardService.php`
- `routes/api.php`
- `tests/Feature/Api/MaterialBoardApiTest.php`

**Estimated scope:** Medium: 4 files

## Task 12: APK — papan material (tabel D dan D+1)

**Description:** Layar tingkat atas baru berbentuk tabel: baris = material, kolom
= D dan D+1, masing-masing dengan qty dan tombol DETAILS. Menggantikan daftar WO
sebagai layar utama.

**Acceptance criteria:**
- [x] Layar menampilkan tabel dua kolom tanggal dengan qty per material.
- [x] Baris menampilkan nama material, model, dan ukuran.
- [x] Tombol DETAILS tersedia per tanggal per material.
- [x] Pencarian material berdasarkan nama, nomor part, atau model.
- [x] Kondisi kosong dan gagal muat dibedakan, dengan tombol coba lagi.
- [x] Seluruh teks baru tersedia dalam tiga bahasa (id, en, ko).
- [x] `flutter analyze` bersih.

**Verification:**
- [x] Tests pass: `flutter test` (52 test) — termasuk 7 widget test papan material
- [x] Lint: `flutter analyze` — no issues
- [x] Manual check: digantikan widget test untuk dua kolom tanggal, tombol nonaktif, dan penyaringan
- [ ] **Verifikasi visual belum dilakukan** — lihat catatan di Task 9

**Catatan implementasi:**
- Papan material kini menjadi layar utama APK; `/work-orders` tetap terdaftar tetapi
  tidak lagi ditautkan dari mana pun (kandidat penghapusan, perlu keputusan).
- Tombol DETAILS dinonaktifkan pada tanggal yang tidak punya kebutuhan.
- Banner "WO menunggu dijadwalkan" dibawa dari Task 2 agar tidak hilang.

**Dependencies:** Task 10

**Files likely touched:**
- `lib/features/material_board/material_board_screen.dart`
- `lib/models/material_board.dart`
- `lib/repositories/material_board_repository.dart`
- `lib/core/l10n/app_localizations.dart`

**Estimated scope:** Medium: 4 files

## Task 12b: APK — layar DETAILS dan navigasi ke alur scan

**Description:** Layar DETAILS per material per tanggal: daftar WO beserta qty, dan
daftar subspart beserta invoice. Memilih WO membuka alur scan.

**Acceptance criteria:**
- [x] DETAILS memuat daftar WO beserta qty per WO.
- [x] DETAILS memuat subspart beserta invoice dan supplier.
- [x] Memilih WO membuka alur scan WO tersebut.
- [x] Rute baru terdaftar dan kembali ke papan dengan benar.
- [x] Seluruh teks baru tersedia dalam tiga bahasa (id, en, ko).
- [x] `flutter analyze` bersih.

**Verification:**
- [x] Tests pass: `flutter test` (52 test)
- [x] Lint: `flutter analyze` — no issues
- [x] Manual check: digantikan widget test navigasi dan test model DETAILS
- [ ] **Verifikasi visual belum dilakukan** — lihat catatan di Task 9

**Catatan implementasi:**
- Menambah tipe `MaterialSummary` tersendiri: objek `material` pada DETAILS
  bentuknya berbeda dari baris papan (tanpa `day_*`, dengan `required_qty`).
- Golden screenshot papan dan DETAILS tersedia di
  `test/golden/goldens/material_board_screen.png` dan
  `material_details_screen.png`.

**Dependencies:** Task 12, Task 11

**Files likely touched:**
- `lib/features/material_board/material_details_screen.dart`
- `lib/router/app_router.dart`
- `lib/core/l10n/app_localizations.dart`

**Estimated scope:** Medium: 3 files

## Checkpoint: Papan Material

- [x] Test API dan `flutter analyze` lulus (API 209 test, APK 52 test)
- [x] Angka D/D+1 sesuai perhitungan porsi hari
- [x] DETAILS memuat WO dan subspart beserta invoice
- [ ] Review dengan manusia sebelum lanjut ke lokasi
- [ ] **Verifikasi visual papan dan DETAILS oleh manusia** — saya tidak bisa membaca gambar, jadi tata letak wajib Anda periksa lewat golden screenshot

## Task 8: APK — layar issue: empat angka, progres material, tag terlipat

**Description:** Layar issue memisahkan "Sudah di-issue" (dari dokumen issue) dan
"Ter-scan sesi ini" (belum disimpan) sebagai dua angka berbeda, menampilkan
progres tiap material, dan menaruh daftar tag di balik tombol per material agar
kartu tetap ringkas. Lingkup dipersempit ke layar issue saja; papan material
ditangani Task 10–12.

**Acceptance criteria:**
- [x] Layar menampilkan empat angka terpisah: Kebutuhan, Sudah di-issue, Ter-scan sesi ini, Sisa.
- [x] Sisa dihitung dari kebutuhan dikurangi sudah di-issue dan ter-scan sesi ini.
- [x] Tiap material menampilkan qty bagian alokasi dan progres terpenuhi.
- [x] Daftar tag tersembunyi sampai tombol per material ditekan, lalu memuat seluruh tag.
- [x] Baris scan sesi berjalan tetap menyediakan aksi ubah qty dan hapus.
- [x] Banner offline tetap muncul saat tidak ada koneksi.
- [x] Seluruh teks baru tersedia dalam tiga bahasa (id, en, ko).
- [x] `flutter analyze` bersih.

**Verification:**
- [x] Tests pass: `flutter test` (60 test) — termasuk 5 widget test baru dan 4 test provider
- [x] Lint: `flutter analyze` — no issues
- [x] Manual check: digantikan test empat angka, progres per material, dan pelipatan tag

**Catatan implementasi:**
- **Perbaikan perilaku inti:** `remainingFor` kini mengurangi `issuedQty` (dokumen
  pengeluaran), bukan hanya scan sesi ini. Dampaknya juga memperketat `addScan` dan
  `updateQty`, yang sebelumnya mengizinkan scan melebihi sisa setelah material
  keluar. Empat test provider mengunci perilaku baru ini.
- Menambah `scannedForMaterial(itemId, partId)` untuk progres per material.
- Progres ditampilkan sebagai teks **dan** bilah progres, sehingga status terbaca
  tanpa mengandalkan warna.
- Test lama `shows the tag with its invoice and supplier` ikut disesuaikan karena
  tag kini terlipat. Asersi invoice/supplier tetap di test itu, dan test baru hanya
  menguji pelipatan — supaya tidak ada dua test yang menguji hal sama.
- Golden screenshot layar issue diregenerasi karena tata letaknya berubah.

**Dependencies:** Task 6

**Files likely touched:**
- `lib/features/issue/issue_screen.dart`
- `lib/providers/issue_provider.dart`
- `lib/models/release_context.dart`
- `lib/core/l10n/app_localizations.dart`

**Estimated scope:** Medium: 4 files

## Checkpoint: Layar Issue

- [x] "Sudah di-issue" dan "Ter-scan sesi ini" terpisah tegas
- [x] Daftar tag terbuka per material
- [ ] **Verifikasi visual layar issue oleh manusia** — golden `issue_screen.png` sudah diregenerasi

## Task 13: Web — migrasi, model, dan permission lokasi

**Description:** Tabel master `locations` dengan migrasi aditif ber-rollback,
model `Location`, dan pendaftaran permission lokasi pada seeder peran.

**Acceptance criteria:**
- [x] Tabel `locations` dibuat dengan migrasi aditif yang punya rollback.
- [x] Model `Location` memakai kolom audit yang sama dengan master lain.
- [x] Kode lokasi unik di tingkat database.
- [x] Permission lokasi terdaftar pada seeder peran.
- [x] Seeder tetap idempoten saat dijalankan ulang.

**Verification:**
- [x] Tests pass: `php artisan test --compact tests/Feature/LocationTest.php` (13 test, 42 assertions)
- [x] Lint: `vendor/bin/pint --dirty --format agent`
- [x] Full suite: `php artisan test --compact` (222 test, 1278 assertions)
- [x] Manual check: `php artisan migrate` dijalankan dan sukses

**Dependencies:** None

**Files likely touched:**
- `database/migrations/*_create_locations_table.php`
- `app/Models/Location.php`
- `database/seeders/RolesAndPermissionsSeeder.php`
- `tests/Feature/LocationTest.php`

**Estimated scope:** Small: 4 files

## Task 13b: Web — CRUD master lokasi

**Description:** Halaman Master → Lokasi dengan index/create/store/edit/update/
destroy, policy, dan teks tiga bahasa. Lokasi dinonaktifkan, bukan dihapus.

**Acceptance criteria:**
- [x] Daftar lokasi tampil dengan pencarian dan paginasi.
- [x] Lokasi dapat dibuat, diubah, dan dinonaktifkan.
- [x] Kode duplikat ditolak dengan pesan jelas.
- [x] Lokasi yang sudah dipakai tidak dapat dihapus, hanya dinonaktifkan.
- [x] Otorisasi dijaga pada setiap aksi.
- [x] Teks baru tersedia dalam tiga bahasa web (id/en/ko).

**Verification:**
- [x] Tests pass: `php artisan test --compact tests/Feature/LocationTest.php`
- [x] Lint: `vendor/bin/pint --dirty --format agent`
- [x] Build: `npm run build`
- [x] Manual check: digantikan test store, update, hapus, dan penolakan hapus

**Catatan implementasi (deviasi yang disengaja):**
- **Tidak memakai Policy.** Master lain (mis. `MachineController`) tidak punya policy
  dan hanya dijaga middleware `auth`. Untuk lokasi saya memakai middleware
  `permission:` sesuai AGENTS.md ("Permission dicek via middleware alias
  `permission`"), yang ternyata **belum dipakai sama sekali** di repo ini. Jadi
  lokasi adalah master pertama yang benar-benar menegakkan permission; master lain
  dibiarkan apa adanya (di luar scope).

**Dependencies:** Task 13

**Files likely touched:**
- `app/Http/Controllers/LocationController.php`
- `app/Policies/LocationPolicy.php`
- `resources/js/Pages/Master/Location/*`
- `resources/js/i18n/catalogs/*.ts`
- `tests/Feature/LocationTest.php`

**Estimated scope:** Medium: 5 files

## Task 13c: Web — label QR lokasi

**Description:** Halaman cetak label QR per lokasi, mengikuti pola label mesin yang
sudah ada, berisi JSON `{type, location_id, location_code, location_name}`.

**Acceptance criteria:**
- [x] Halaman label dapat dibuka per lokasi dan menampilkan QR.
- [x] Isi QR berupa JSON dengan `type` bernilai `location`.
- [x] Lokasi nonaktif ditolak saat mencetak label (404)
- [x] Otorisasi tetap berlaku.

**Verification:**
- [x] Tests pass: `php artisan test --compact tests/Feature/LocationTest.php`
- [x] Lint: `vendor/bin/pint --dirty --format agent`
- [x] Build: `npm run build`
- [x] Manual check: digantikan test render label (kode, nama, dan jenis label)

**Catatan implementasi:**
- Isi QR memakai `Location::qrPayload()`, bentuknya sama seperti label mesin.
- Cetak otomatis saat halaman dibuka (`window.print()`), mengikuti pola label mesin.

**Dependencies:** Task 13

**Files likely touched:**
- `app/Http/Controllers/LocationController.php`
- `tests/Feature/LocationTest.php`

**Estimated scope:** Small: 2 files

## Task 14: Web — penerimaan menyimpan lokasi + layar Setup Lokasi

**Description:** Form penerimaan menambahkan pilihan lokasi yang divalidasi
terhadap master dan disimpan pada `incoming_receives.location_code`. Layar Setup
Lokasi menampilkan penerimaan/stok yang belum berlokasi dan menetapkannya.

**Acceptance criteria:**
- [x] Penerimaan menyimpan lokasi pada `incoming_receives.location_code`.
- [x] Penerimaan menolak kode lokasi yang tidak ada di master atau nonaktif.
- [x] Penerimaan tetap dapat disimpan tanpa lokasi.
- [x] Layar Setup Lokasi menampilkan daftar yang belum berlokasi.
- [x] Layar Setup Lokasi menetapkan lokasi secara massal.
- [x] Teks baru tersedia dalam tiga bahasa web (id/en/ko).

**Verification:**
- [x] Tests pass: `php artisan test --compact tests/Feature/LocationTest.php` (22 test, 76 assertions)
- [x] Lint: `vendor/bin/pint --dirty --format agent`
- [x] Build: `npm run build`
- [x] Full suite: `php artisan test --compact` (231 test, 1312 assertions)
- [x] Manual check: digantikan test simpan, tolak kode asing, dan penugasan massal

**Catatan implementasi:**
- Validasi lokasi memakai `Rule::exists('locations','code')->where('is_active', true)`,
  jadi lokasi nonaktif ditolak tanpa perlu foreign key.
- Pada `update`, `location_code` hanya ditulis bila field-nya benar-benar dikirim,
  supaya klien lama yang tidak mengirim field itu tidak menghapus lokasi.
- **Di luar scope Task 14:** endpoint penerimaan mobile belum mengirim lokasi; form web
  yang diubah. APK receiving belum punya pilihan lokasi.

**Dependencies:** Task 13

**Files likely touched:**
- `app/Http/Controllers/ReceiveController.php`
- `app/Http/Controllers/LocationSetupController.php`
- `resources/js/Pages/Incoming/*`
- `resources/js/Pages/Master/Location/Setup.vue`
- `tests/Feature/LocationTest.php`

**Estimated scope:** Medium: 5 files

## Task 15: API — resolve QR lokasi + catat lokasi saat pengeluaran

**Description:** Endpoint `POST /api/locations/resolve` untuk QR lokasi, dan
`POST /api/work-orders/{wo}/release` menerima `location_code` opsional yang
divalidasi lalu dicatat pada dokumen pengeluaran.

**Acceptance criteria:**
- [x] `POST /api/locations/resolve` mengembalikan kode dan nama lokasi.
- [x] Lokasi tidak dikenal atau nonaktif menghasilkan 404.
- [x] `material_issues` menyimpan `location_code` hasil pindai.
- [x] Pengeluaran tetap berhasil tanpa `location_code` (kompatibel klien lama).
- [x] `location_code` yang tidak ada di master ditolak dengan pesan jelas.
- [x] Lokasi yang berbeda dari data penerimaan tidak menghalangi pengeluaran.
- [x] Otorisasi tetap berlaku.

**Verification:**
- [x] Tests pass: `php artisan test --compact tests/Feature/Api/MaterialIssueApiTest.php` (63 test, 299 assertions)
- [x] Lint: `vendor/bin/pint --dirty --format agent`
- [x] Full suite: `php artisan test --compact` (240 test, 1343 assertions)
- [x] Manual check: digantikan test release dengan lokasi, tanpa lokasi, dan rak berbeda

**Catatan implementasi:**
- Migrasi aditif `material_issues.location_code` (nullable, terindeks, ada rollback).
  Lokasi disimpan sebagai teks, bukan foreign key, agar riwayat lama tetap sah bila
  lokasi dinonaktifkan.
- **Tambahan di luar kriteria:** `POST /api/stock-tags/resolve` kini juga
  mengembalikan `location_code` (rak yang tercatat saat penerimaan). Tanpa ini APK
  tidak punya pembanding, sehingga peringatan "rak berbeda" di Task 16 mustahil.

**Dependencies:** Task 13

**Files likely touched:**
- `app/Http/Controllers/Api/LocationApiController.php`
- `app/Http/Controllers/Api/MaterialIssueApiController.php`
- `app/Services/WoService.php`
- `database/migrations/*_add_location_code_to_material_issues.php`
- `routes/api.php`
- `tests/Feature/Api/MaterialIssueApiTest.php`

**Estimated scope:** Medium: 6 files

## Task 16: APK — langkah scan lokasi pada alur issue

**Description:** Setelah material cocok, layar issue meminta operator memindai QR
lokasi rak. Lokasi yang dipindai dicatat, ditampilkan sebelum pengiriman, dan
dikirim bersama pengeluaran. Peringatan muncul bila berbeda dari data penerimaan,
tanpa memblokir.

**Acceptance criteria:**
- [x] Konfirmasi kecocokan material tampil setelah pindai berhasil.
- [x] Langkah scan lokasi tampil setelah material cocok (tombol nonaktif sampai ada material ter-scan).
- [x] Lokasi yang dipindai terlihat sebelum pengiriman dikirim.
- [x] Peringatan tampil bila lokasi berbeda, dan pengiriman tetap dapat dilanjutkan.
- [x] Pengiriman tanpa lokasi tetap berhasil.
- [x] QR lokasi tidak dikenal menampilkan pesan jelas.
- [x] Seluruh teks baru tersedia dalam tiga bahasa (id, en, ko).
- [x] `flutter analyze` bersih.

**Verification:**
- [x] Tests pass: `flutter test` (70 test) — 6 test provider + 2 test model + 1 widget test baru
- [x] Lint: `flutter analyze` — no issues
- [x] Manual check: digantikan test provider untuk setLocation, peringatan rak, dan pengiriman berisi lokasi
- [ ] **Jalur kamera belum diuji otomatis** — ScanScreen memakai kamera, tidak bisa didorong di widget test

**Catatan implementasi:**
- Tombol "Scan Lokasi" nonaktif sampai ada material ter-scan, mengikuti alur PRD.
- `resolveTag` mengembalikan `location_code` dari penerimaan, dan `ScanLine`
  menyimpannya, sehingga peringatan rak berbeda bisa dihitung di APK.
- Peringatan dihitung dari **semua** tag yang ter-scan: bila ada satu saja yang
  raknya berbeda, peringatan tampil. Pengiriman tetap dapat dilanjutkan.
- Golden layar issue diregenerasi.

**Dependencies:** Task 8, Task 15

**Files likely touched:**
- `lib/features/issue/issue_screen.dart`
- `lib/providers/issue_provider.dart`
- `lib/repositories/issue_repository.dart`
- `lib/models/location_ref.dart`
- `lib/core/l10n/app_localizations.dart`

**Estimated scope:** Medium: 5 files

## Checkpoint: Lokasi Rak

- [ ] QR lokasi dapat dicetak dan di-resolve
- [ ] Pengeluaran mencatat lokasi dan tetap berhasil saat rak berbeda
- [ ] Pengeluaran tanpa lokasi tetap berhasil
- [ ] Review dengan manusia sebelum verifikasi akhir

## Task 9: Verifikasi menyeluruh

**Description:** Menjalankan seluruh gerbang mutu, memeriksa diff, dan memastikan
tidak ada regresi pada alur produksi lain di ERP maupun APK.

**Acceptance criteria:**
- [ ] Seluruh Success Criteria keempat PRD aktif terpenuhi — **belum penuh**, lihat catatan
- [x] Suite API dan suite penuh ERP lulus.
- [x] Pint lulus pada berkas PHP yang berubah.
- [x] `flutter test` dan `flutter analyze` lulus.
- [x] `npm run build` lulus.
- [x] Tidak ada test lama yang dihapus atau dilemahkan.
- [x] Tidak ada perubahan skema di luar `locations` dan `material_issues.location_code`.

**Verification:**
- [x] Tests pass: `php artisan test --compact` (240 test, 1343 assertions)
- [x] Lint: `vendor/bin/pint --dirty --format agent`
- [x] Build: `npm run build`
- [x] APK: `flutter test` (70 test) dan `flutter analyze` (no issues)
- [ ] Manual check: **belum dijalankan** — saya tidak bisa menjalankan aplikasi terhadap server

**Celah yang ditutup setelah audit `get-design`:**
1. ~~Lokasi nonaktif masih bisa dicetak labelnya~~ → kini 404, ada test.
2. ~~Material Subcon diverifikasi review kode saja~~ → kini ada test di papan material.
3. Target sentuh < 44dp, 4 kontrol tanpa nama, teks < 11px, container ber-border
   bersarang, 24 tabel web terpotong, hover kontras 4.04, dan indikator navigasi
   web — semuanya diperbaiki dan diverifikasi ulang.

**Celah yang masih terbuka (jujur, bukan klaim selesai):**
1. **Endpoint penerimaan mobile belum mengirim lokasi**; yang berubah hanya form web.
   APK receiving belum punya pemilih lokasi. Ini slice baru, bukan perbaikan.
2. **Jalur kamera** (scan material & scan lokasi) belum diuji otomatis — `ScanScreen`
   memakai plugin kamera yang tidak bisa didorong di widget test.
3. **Verifikasi visual** oleh manusia belum dilakukan untuk papan material, DETAILS,
   dan layar issue. Golden tersedia di `test/golden/goldens/`.
4. **`work_orders_screen.dart` masih kode mati** — tidak ditautkan sejak papan
   material menjadi layar utama. **Perlu keputusan**: hapus atau tautkan.

**Dependencies:** Task 8 sampai Task 16

**Files likely touched:**
- Tidak ada berkas baru (verifikasi saja)

**Estimated scope:** Small: verification-only

## Checkpoint: Selesai

- [ ] Seluruh Success Criteria keempat PRD aktif terpenuhi — **belum penuh** (6 celah di Task 9)
- [x] Seluruh gerbang mutu otomatis lulus (240 test ERP, 70 test APK, Pint, build, analyze)
- [ ] Siap direview — menunggu verifikasi visual dan keputusan pada celah di atas

