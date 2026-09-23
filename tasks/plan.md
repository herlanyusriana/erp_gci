# Implementation Plan: Sembunyikan Baris WO yang Sudah Tuntas di Production Plan

## Overview

Papan Production Plan saat ini menampilkan semua baris WO, termasuk yang sudah
tuntas diproduksi (sisa 0). Operator jadi merasa harus "clear WO" dulu, padahal
tidak ada aksi itu di sistem. Perubahan ini menyaring papan agar hanya memuat
baris WO yang masih bersisa, sehingga WO lama yang tuntas hilang otomatis dan
WO baru bisa dikerjakan tanpa langkah manual.

PRD: `docs/prd/hide-completed-wo-rows.md`

## Architecture Decisions

- **Satu titik perubahan utama:** `ProductionPlanController::index()`. Tidak ada
  perubahan skema database.
- **"Closed" derived, bukan status tersimpan.** Baris dianggap tuntas ketika
  `remaining_qty` = 0. Alasan: Production Result dapat dihapus
  (`production-results.destroy`); nilai turunan otomatis memunculkan kembali
  baris, sedangkan status tersimpan akan tertinggal salah.
- **Satuan "closed" = baris papan** (pasangan `work_order_id` + `wip_part_id`),
  bukan WO global. Satu WO boleh tuntas di satu mesin dan masih bersisa di
  mesin lain.
- **Rumus sisa tidak diubah:** `max(0, qty WO − Σ qty_good untuk pasangan itu,
  result_date ≤ tanggal papan)`. Penyaringan memakai nilai yang sudah dihitung.
- **Panel "aktif di tanggal lain" diselaraskan:** WO yang seluruh baris internalnya
  tuntas tidak lagi ditampilkan; hasil setelah tanggal papan dan baris Subcon
  tidak ikut mengurangi atau mempertahankan beban papan.
- **WO `planned` dan `cancelled` tidak dihitung**; `in_progress` dan `completed`
  yang masih bersisa tetap tampil. Alur release tidak disentuh.
- **Status WO tidak diubah** oleh penyaringan ini.

## Task List

### Phase 1: Perilaku Inti

- [x] Task 1: Sembunyikan baris papan yang sisanya 0
- [x] Task 2: Selaraskan panel "aktif di tanggal lain"

### Checkpoint: Perilaku Inti

- [x] Semua test `ProductionPlanTest` lulus
- [x] Perilaku diverifikasi lewat HTTP (Inertia props), bukan asumsi

### Phase 2: Verifikasi

- [x] Task 3: Verifikasi menyeluruh (suite penuh + Pint + build)

### Checkpoint: Selesai

- [x] Seluruh Success Criteria PRD terpenuhi
- [x] Siap direview

## Risks and Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Baris sah ikut tersembunyi karena relasi WO ter-soft-delete | Sedang | Disengaja: baris yatim memang tidak punya arti operasional. Dicatat di PRD. |
| Ringkasan papan (jumlah mesin/WO) jadi tidak konsisten | Rendah | Ringkasan dihitung dari daftar baris, jadi otomatis ikut. Diverifikasi di test. |
| Hasil produksi dihapus membuat baris tidak kembali | Tinggi | Derived, bukan status tersimpan. Test khusus: hapus result -> baris muncul lagi. |
| Sisa pecahan sangat kecil dianggap belum tuntas | Rendah | Dicatat sebagai Open Question di PRD; belum perlu toleransi sekarang. |

## Open Questions

- Ambang toleransi nol (mis. `> 1e-9`) bila muncul sisa pecahan akibat
  pembulatan. Belum diperlukan.
- Penanda "closed" tersimpan bila nanti diminta (perubahan skema, perlu
  persetujuan terpisah). PRD memilih derived.

## Task List Detail

### Task 1: Sembunyikan baris papan yang sisanya 0

**Description:** Setelah `remaining_qty` dihitung per baris di
`ProductionPlanController::index()`, saring `$items` agar hanya memuat baris
dengan sisa lebih dari 0. Ringkasan papan dan kolom estimasi otomatis mengikuti
karena dihitung dari daftar yang sama.

**Acceptance criteria:**
- [ ] Baris WO dengan sisa 0 tidak muncul di prop `items`.
- [ ] WO lama yang masih bersisa tetap tampil di mesinnya.
- [ ] Pada satu WO dengan beberapa baris mesin, hanya baris belum tuntas yang tampil.
- [ ] Menghapus Production Result memunculkan kembali barisnya.
- [ ] Ringkasan papan mencerminkan baris yang tersisa.

**Verification:**
- [ ] Tests pass: `php artisan test --compact tests/Feature/ProductionPlanTest.php`
- [ ] Build succeeds: `npm run build`
- [ ] Manual check: buka papan pada WO yang sudah tuntas, barisnya tidak tampil

**Dependencies:** None

**Files likely touched:**
- `app/Http/Controllers/ProductionPlanController.php`
- `tests/Feature/ProductionPlanTest.php`

**Estimated scope:** Small: 1-2 files plus tests

### Task 2: Selaraskan panel "aktif di tanggal lain"

**Description:** Panel `offBoardWorkOrders` saat ini memuat WO aktif yang
barisnya ada di tanggal lain. Setelah Task 1, WO yang seluruh barisnya tuntas
tidak lagi relevan, jadi harus dikeluarkan dari panel ini agar konsisten.

**Acceptance criteria:**
- [ ] WO yang seluruh barisnya tuntas tidak muncul di `offBoardWorkOrders`.
- [ ] WO yang masih bersisa di tanggal lain tetap muncul beserta tanggal papannya.
- [ ] WO `planned` tetap tidak dihitung.

**Verification:**
- [ ] Tests pass: `php artisan test --compact tests/Feature/ProductionPlanTest.php`
- [ ] Build succeeds: `npm run build`
- [ ] Manual check: WO tuntas di semua mesin tidak tampil di panel biru

**Dependencies:** Task 1

**Files likely touched:**
- `app/Http/Controllers/ProductionPlanController.php`
- `tests/Feature/ProductionPlanTest.php`

**Estimated scope:** Small: 1 file plus tests

### Task 3: Verifikasi menyeluruh

**Description:** Jalankan quality gate penuh, periksa diff, dan pastikan tidak
ada regresi pada papan maupun alur produksi lain.

**Acceptance criteria:**
- [ ] Suite penuh lulus.
- [ ] Pint lulus pada file yang berubah.
- [ ] `npm run build` lulus.
- [ ] Tidak ada test lama yang dihapus atau dilemahkan.

**Verification:**
- [ ] Tests pass: `php artisan test --compact`
- [ ] Build succeeds: `npm run build`
- [ ] Lint: `vendor/bin/pint --dirty --format agent`
- [ ] Manual check: papan Production Plan dibuka dan perilaku sesuai PRD

**Dependencies:** Task 1, Task 2

**Files likely touched:**
- Tidak ada file baru (verifikasi saja)

**Estimated scope:** Small: verification-only
