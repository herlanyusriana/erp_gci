# Task List: Sembunyikan Baris WO yang Sudah Tuntas di Production Plan

PRD: `docs/prd/hide-completed-wo-rows.md`
Plan: `tasks/plan.md`

## Task 1: Sembunyikan baris papan yang sisanya 0

**Description:** Setelah `remaining_qty` dihitung per baris di
`ProductionPlanController::index()`, saring `$items` agar hanya memuat baris
dengan sisa lebih dari 0. Ringkasan papan dan kolom estimasi otomatis mengikuti.

**Acceptance criteria:**
- [x] Baris WO dengan sisa 0 tidak muncul di prop `items`.
- [x] WO lama yang masih bersisa tetap tampil di mesinnya.
- [x] Pada satu WO dengan beberapa baris mesin, hanya baris belum tuntas yang tampil.
- [x] Menghapus Production Result memunculkan kembali barisnya.
- [x] Ringkasan papan mencerminkan baris yang tersisa.

**Verification:**
- [x] Tests pass: `php artisan test --compact tests/Feature/ProductionPlanTest.php`
- [x] Build succeeds: `npm run build`
- [ ] Manual check: buka papan pada WO yang sudah tuntas, barisnya tidak tampil

**Dependencies:** None

**Files likely touched:**
- `app/Http/Controllers/ProductionPlanController.php`
- `tests/Feature/ProductionPlanTest.php`

**Estimated scope:** Small: 1-2 files plus tests

## Task 2: Selaraskan panel "aktif di tanggal lain"

**Description:** Keluarkan WO yang seluruh barisnya sudah tuntas dari
`offBoardWorkOrders`, agar panel hanya berisi alokasi masa depan yang belum
tampil di papan.

**Acceptance criteria:**
- [x] WO yang seluruh barisnya tuntas tidak muncul di `offBoardWorkOrders`.
- [x] Alokasi masa depan tetap muncul beserta tanggal papannya.
- [x] WO `planned` tetap tidak dihitung.

**Verification:**
- [x] Tests pass: `php artisan test --compact tests/Feature/ProductionPlanTest.php`
- [x] Build succeeds: `npm run build`
- [ ] Manual check: WO tuntas di semua mesin tidak tampil di panel biru

**Dependencies:** Task 1

**Files likely touched:**
- `app/Http/Controllers/ProductionPlanController.php`
- `tests/Feature/ProductionPlanTest.php`

**Estimated scope:** Small: 1 file plus tests

## Task 3: Verifikasi menyeluruh

**Description:** Jalankan quality gate penuh dan pastikan tidak ada regresi.

**Acceptance criteria:**
- [x] Suite penuh lulus setelah perubahan carry-over.
- [x] Pint lulus pada file yang berubah.
- [x] `npm run build` lulus.
- [x] Tidak ada test lama yang dihapus atau dilemahkan.

**Verification:**
- [x] Tests pass: `php artisan test --compact`
- [x] Build succeeds: `npm run build`
- [x] Lint: `vendor/bin/pint --dirty --format agent`
- [ ] Manual check: papan Production Plan dibuka dan perilaku sesuai PRD

**Dependencies:** Task 1, Task 2

**Estimated scope:** Small: verification-only

## Task 4: Carry-over alokasi lama yang belum tuntas

**Acceptance criteria:**
- [x] Alokasi Production Plan dengan `plan_date <= tanggal terpilih` tetap tampil bila masih bersisa.
- [x] `work_orders.planned_date` tidak memengaruhi visibilitas papan.
- [x] Alokasi masa depan tidak muncul lebih awal.
- [x] Carry-over tidak diduplikasi di `offBoardWorkOrders`.

**Verification:**
- [x] Tests pass: `php artisan test --compact tests/Feature/ProductionPlanTest.php`
- [x] Full suite, Pint, dan build lulus setelah perubahan.

## Checkpoint: Perilaku Inti (setelah Task 1-2 dan Task 4)

- [x] Semua test `ProductionPlanTest` lulus.
- [x] Perilaku diverifikasi lewat HTTP (Inertia props), bukan asumsi.
- [x] Rumus sisa dan filter Subcon tidak berubah.

## Checkpoint: Selesai (setelah Task 3)

- [x] Seluruh Success Criteria PRD terpenuhi.
- [x] Suite penuh + Pint + build lulus.
- [x] Siap direview.

## Catatan Verifikasi

- Otomatis: 28 test Production Plan / 334 assertions dan 142 test penuh / 916 assertions lulus.
- Pint dan build frontend lulus.
- Verifikasi browser manual pada dua item di atas belum dilakukan pada sesi ini.
