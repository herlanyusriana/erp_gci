# PRD: Sembunyikan Baris WO yang Sudah Tuntas di Production Plan

## Problem Statement

Operator produksi membuka papan Production Plan dan melihat baris WO yang
sudah selesai dikerjakan masih menetap di papan dengan sisa 0. Karena mesin
tampak masih "terisi", muncul kesan bahwa WO lama harus di-clear dulu sebelum
WO baru bisa dikerjakan — padahal di sistem tidak ada aksi clear WO sama
sekali. Ketika satu mesin mengerjakan beberapa WO, baris WO lama yang tuntas
bercampur dengan baris WO baru yang masih bersisa, sehingga papan tidak lagi
mencerminkan beban kerja nyata mesin tersebut.

## Solution

Papan Production Plan hanya menampilkan baris WO yang **masih punya sisa
produksi**. Begitu hasil produksi sebuah WO di sebuah mesin + part output
mencapai qty WO, baris itu dianggap tuntas dan hilang dari papan secara
otomatis. Tidak ada tombol atau langkah "clear WO" yang perlu dijalankan
operator. WO lama yang masih bersisa tetap tampil berdampingan dengan WO baru
untuk mesin yang sama, sehingga satu kolom "Sisa Jumlah WO" selalu menunjukkan
pekerjaan yang benar-benar tersisa.

## User Stories

1. Sebagai operator produksi, saya ingin baris WO yang sudah tuntas hilang
   sendiri dari papan, supaya saya tidak perlu menutup atau menghapusnya
   secara manual sebelum mengerjakan WO berikutnya.
2. Sebagai operator produksi, saya ingin melihat WO lama yang masih bersisa
   tetap tampil di mesinnya, supaya pekerjaan yang belum selesai tidak
   terlewat.
3. Sebagai operator produksi, saya ingin kolom "Sisa Jumlah WO" hanya
   menampilkan angka sisa yang nyata, supaya saya tidak bingung melihat baris
   dengan sisa 0.
4. Sebagai operator produksi, saya ingin bisa mengerjakan WO baru tanpa
   menyentuh WO lama, supaya alur kerja di mesin tidak terhambat.
5. Sebagai planner, saya ingin papan hanya memuat pekerjaan yang tersisa,
   supaya beban tiap mesin terbaca langsung dari papan.
6. Sebagai planner, saya ingin baris yang tuntas di satu mesin tetapi belum
   tuntas di mesin lain tetap muncul hanya di mesin yang belum tuntas, supaya
   progres per step akurat.
7. Sebagai planner, saya ingin WO yang sudah tuntas di semua barisnya tidak
   lagi muncul di panel "aktif di tanggal lain", supaya panel itu tetap
   berisi pekerjaan yang benar-benar menunggu.
8. Sebagai supervisor, saya ingin koreksi hasil produksi (menghapus Production
   Result) membuat baris muncul kembali di papan, supaya kesalahan input tidak
   membuat pekerjaan hilang dari pengawasan.
9. Sebagai pengguna dengan permission terbatas, saya ingin perilaku papan ini
   tidak memberi saya akses tambahan apa pun, supaya perubahan ini tidak
   melemahkan batas otorisasi yang ada.

## Objective

Menghilangkan kebutuhan "clear WO" pada alur kerja mesin dengan menampilkan
hanya baris WO yang masih bersisa di papan Production Plan.

Pengguna: operator produksi, planner, dan supervisor di halaman Production
Plan (web).

Ukuran keberhasilan: setelah sebuah WO tuntas diproduksi di sebuah mesin,
barisnya tidak lagi tampil di papan tanpa aksi manual apa pun, sementara WO
lain yang masih bersisa di mesin yang sama tetap tampil normal.

## Technical Decisions

- **Titik perubahan tunggal.** `ProductionPlanController::index()` memilih
  alokasi sampai tanggal papan dan status WO yang relevan, lalu menyaring
  `remaining_qty` per baris. Tidak ada perubahan skema database.
- **"Closed" bersifat derived, bukan status tersimpan.** Baris dianggap tuntas
  ketika `remaining_qty` = 0. Tidak ada kolom status baru. Alasan: Production
  Result dapat dihapus melalui `DELETE production-results/{workOrder}/results/{result}`;
  dengan nilai turunan, menghapus hasil produksi otomatis memunculkan kembali
  barisnya, sedangkan status tersimpan akan tertinggal pada kondisi "closed"
  yang salah.
- **Satuan "closed" adalah baris papan, bukan WO global.** Rumus sisa tetap per
  pasangan `(work_order_id, wip_part_id)`, yaitu per mesin + part output. Satu
  WO bisa tuntas di satu mesin dan masih bersisa di mesin lain; hanya baris
  yang tuntas yang disembunyikan.
- **Rumus sisa tidak berubah:** `max(0, qty WO − Σ Production Result qty_good
  untuk pasangan itu, dibatasi result_date ≤ tanggal papan)`.
- **Alokasi Production Plan menentukan visibilitas.** `work_orders.planned_date`
  hanya informasi tanggal WO dan tidak dipakai untuk memilih baris papan.
  Saat membuka tanggal D, papan memuat alokasi dengan `production_plans.plan_date
  ≤ D` yang masih bersisa. Alokasi lama tidak dipindah atau diduplikasi.
- **WO `planned` tetap tidak dihitung** dan tidak masuk papan. WO masuk papan
  saat release (perilaku yang sudah berlaku), sehingga penyaringan ini tidak
  menyentuh alur release.
- **Status WO tidak diubah.** Baris yang tuntas tidak mengubah status WO menjadi
  `completed`; penutupan WO tetap keputusan planner.
- **Panel "aktif di tanggal lain" diselaraskan.** Carry-over tanggal lama tampil
  langsung di papan. `offBoardWorkOrders` hanya memuat alokasi setelah tanggal
  papan terpilih dan tidak menduplikasi WO yang sudah tampil.
- **Baris yatim ikut teratasi.** Query hanya memuat item yang masih memiliki
  relasi `workOrder`, sehingga baris milik WO yang di-soft-delete tidak masuk
  papan.
- **Ringkasan papan ikut menyesuaikan sendiri.** Jumlah mesin dan WO pada
  ringkasan dihitung dari daftar baris, sehingga otomatis mengikuti penyaringan.
- **Urutan kolom dan target D/D+1/D+2 tetap per WO.** Tidak ada perubahan pada
  struktur tabel papan.

## Project Structure

    app/Http/Controllers/ProductionPlanController.php  -> titik perubahan (index)
    resources/js/Pages/Production/Plan/Index.vue       -> papan; tanpa perubahan struktur
    tests/Feature/ProductionPlanTest.php               -> regresi perilaku papan
    docs/prd/hide-completed-wo-rows.md                 -> dokumen ini

## Commands

    Build: flutter tidak relevan; frontend web: npm run build
    Test:  php artisan test --compact tests/Feature/ProductionPlanTest.php
    Lint:  vendor/bin/pint --dirty --format agent
    Dev:   composer run dev

## Testing Strategy

Fokus pada perilaku yang teramati lewat HTTP (Inertia props), bukan detail
implementasi. Prior art: `tests/Feature/ProductionPlanTest.php` sudah memuat
pola `assertInertia` untuk `items`, `avail_qty`, dan `offBoardWorkOrders`;
test baru mengikuti pola dan gaya yang sama.

Test yang ditambahkan:

- Baris WO dengan sisa 0 tidak muncul di prop `items`.
- Satu WO dengan dua baris mesin, satu tuntas dan satu belum, hanya
  menampilkan baris yang belum tuntas.
- Menghapus Production Result memunculkan kembali barisnya di papan.
- WO yang seluruh barisnya tuntas tidak muncul di `offBoardWorkOrders`.
- WO dari alokasi tanggal sebelumnya tetap muncul langsung di papan sampai tuntas.
- Hasil produksi setelah tanggal papan tidak menutup baris carry-over historical.
- Alokasi masa depan tidak muncul sebelum tanggal alokasinya.
- `work_orders.planned_date` tidak mengontrol visibilitas papan.
- Baris Subcon tidak dihitung sebagai beban internal pada `offBoardWorkOrders`.
- WO `planned`/`cancelled` tidak tampil; WO `completed` yang masih bersisa tetap tampil.

Regresi yang harus tetap lulus: `test_release_enters_wo_into_plan_board`,
`test_plan_remaining_qty_uses_results_up_to_board_date_not_targets`,
`test_plan_rows_follow_routing_order`,
`test_subcon_machine_rows_are_hidden_from_plan_board`,
`test_unfinished_work_order_from_previous_plan_date_carries_over_to_selected_board`.

Karena perhitungan ada di controller dan tidak ada logika murni terpisah,
cakupan lewat feature test sudah memadai; unit test terpisah tidak diperlukan.

## Boundaries

- **Always:** menjalankan `php artisan test --compact tests/Feature/ProductionPlanTest.php`
  dan `vendor/bin/pint --dirty --format agent` sebelum menyatakan selesai;
  mempertahankan rumus sisa yang ada; menjaga filter Subcon dan urutan mesin.
- **Ask first:** menambah kolom atau status baru pada `production_plan_items`;
  mengubah status WO secara otomatis; mengubah alur release; menambah aksi
  "clear WO".
- **Never:** mengubah atau menghapus baris papan secara permanen sebagai efek
  samping penyaringan; menyembunyikan baris yang masih bersisa; menghapus
  Production Result; melemahkan otorisasi `production_plan.view` /
  `production.view`; mengubah data produksi.

## Out of Scope

- Menggabungkan beberapa WO menjadi satu baris agregat per mesin (opsi A
  ditolak; baris tetap per WO).
- Menambah kolom status tersimpan atau fitur "closed" eksplisit pada baris.
- Tombol atau aksi "clear WO" baru.
- Mengubah target D/D+1/D+2 menjadi agregat.
- Menambah catatan riwayat (log) saat baris tersembunyi otomatis.
- Menghitung WO `planned` (belum release) ke dalam papan.
- Mengubah rumus sisa atau batas tanggal papan.

## Success Criteria

- [x] Baris WO dengan sisa 0 tidak muncul lagi di prop `items` papan Production Plan.
- [x] WO lama yang masih bersisa tetap tampil di mesinnya, berdampingan dengan WO baru.
- [x] Alokasi Production Plan tanggal sebelumnya dibawa ke papan tanggal terpilih sampai tuntas.
- [x] Alokasi Production Plan masa depan tidak muncul lebih awal.
- [x] `work_orders.planned_date` tidak memengaruhi visibilitas papan.
- [x] Pada satu WO dengan beberapa baris mesin, hanya baris yang belum tuntas yang tampil.
- [x] Menghapus Production Result memunculkan kembali baris yang bersangkutan di papan.
- [x] WO yang seluruh barisnya tuntas tidak muncul di panel "aktif di tanggal lain".
- [x] WO `planned` tetap tidak muncul di papan.
- [x] Status WO tidak berubah sebagai efek dari penyaringan ini.
- [x] Ringkasan papan (jumlah mesin dan WO) mencerminkan baris yang tersisa saja.
- [x] Seluruh test di `tests/Feature/ProductionPlanTest.php` lulus, termasuk test regresi lama.
- [x] `vendor/bin/pint --dirty --format agent` lulus.

## Open Questions

1. **Definisi "closed" derived vs tersimpan.** PRD ini memilih derived (sisa 0
   = closed, tanpa kolom status). Bila Pak Arnold menghendaki penanda eksplisit
   yang tersimpan di baris papan, ini berubah menjadi perubahan skema dan harus
   disetujui lebih dulu.
2. **Ambang toleransi nol.** Saat ini perbandingan memakai `> 0` setelah
   `max(0, ...)`. Bila nanti muncul sisa pecahan sangat kecil akibat pembulatan
   produksi, perlu diputuskan apakah ambang perlu toleransi kecil (mis.
   `> 1e-9`). Belum diperlukan sekarang.
3. **Riwayat tersembunyi.** Bila nanti dibutuhkan jejak "baris X tersembunyi
   otomatis", perlu penambahan pencatatan tersendiri. Dikesampingkan untuk
   iterasi ini.
