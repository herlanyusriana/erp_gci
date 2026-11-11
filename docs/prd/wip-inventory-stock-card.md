# PRD: WIP Inventory Stock Card (WIP & FG / Raw Material)

## Problem Statement

Petugas PPIC, gudang, dan produksi perlu tahu, untuk periode tertentu, berapa material yang masuk ke tiap mesin, berapa yang terpakai, dan berapa sisanya. Halaman WIP Inventory saat ini mencampur barang setengah jadi dengan raw material dalam satu tabel pergerakan, dan tab satunya hanya menampilkan snapshot stok fisik WIP saat ini. Akibatnya laporan tidak bisa dipecah per kategori barang, tidak bisa dibaca untuk satu periode, dan barang jadi (FG) tidak pernah muncul sama sekali.

## Solution

WIP Inventory menjadi satu halaman dengan dua tab kartu stok per mesin:

1. **WIP & FG** — barang setengah jadi dan barang jadi, dikelompokkan menurut **mesin yang memproduksinya** (bukan mesin yang memakainya).
2. **Raw Material** — material murni, dikelompokkan menurut **mesin yang menerima dan memakainya**, termasuk tambahan raw material di proses berikutnya.

Kedua tab memakai format yang sama: Stok Awal, Receive, Issue, Stok Akhir untuk rentang tanggal yang dipilih, dengan penyaring mesin.

## User Stories

1. Sebagai petugas PPIC, saya ingin melihat kartu stok barang setengah jadi per mesin untuk rentang tanggal tertentu, sehingga saya tahu berapa WIP yang masuk, terpakai, dan tersisa.
2. Sebagai petugas PPIC, saya ingin melihat kartu stok raw material per mesin, sehingga tambahan raw material di mesin proses berikutnya ikut tercatat.
3. Sebagai petugas gudang, saya ingin setiap baris menampilkan Stok Awal, Receive, Issue, dan Stok Akhir, sehingga saya bisa merekonsiliasi laporan dengan kondisi fisik.
4. Sebagai petugas PPIC, saya ingin memfilter berdasarkan mesin, sehingga saya bisa fokus pada satu mesin saat investigasi selisih.
5. Sebagai petugas PPIC, saya ingin rentang tanggal default bulan berjalan (tanggal 1 sampai hari ini), sehingga laporan periode langsung terbuka tanpa mengatur tanggal dulu.
6. Sebagai petugas PPIC, saya ingin barang jadi yang diproduksi muncul sebagai Receive dengan Issue 0 selama belum ada pengiriman FG, sehingga output akhir proses tetap terlihat di laporan.
7. Sebagai petugas PPIC, saya ingin baris yang hanya memiliki stok awal (tanpa mutasi dalam rentang) tetap tampil, sehingga stok yang tidak bergerak tidak hilang dari laporan.
8. Sebagai petugas PPIC, saya ingin membuka rincian per nota untuk satu mesin dan part, sehingga saya bisa menelusuri asal material dan pemakaiannya.
9. Sebagai pengguna dengan izin produksi, saya ingin halaman ini hanya membaca data (tidak mengubah stok), sehingga laporan tidak bisa merusak saldo.

## Objective

Menyediakan kartu stok WIP/FG dan raw material per mesin yang konsisten dengan event produksi, untuk periode yang bisa dipilih. Pengguna utama adalah PPIC dan gudang produksi. Keberhasilan berarti: untuk rentang tanggal apa pun, Stok Akhir setiap baris sama dengan Stok Awal + Receive − Issue, kategori WIP/FG dan raw material terpisah, dan tidak ada stok yang hilang dari laporan.

## Technical Decisions

**Modules yang berubah**

- `App\Services\WipInventoryService` — menerima rentang tanggal, mengklasifikasikan baris per tipe part, dan menambahkan output FG sebagai penerimaan.
- `App\Http\Controllers\ProductionReceiptController` — memvalidasi/menormalkan filter `from`, `to`, `machine_id`, `tab`; meneruskan data ke halaman; menghapus prop snapshot.
- `resources/js/Pages/Production/Receipt/Index.vue` — dua tab, filter rentang tanggal + mesin, tanpa tab snapshot.
- `resources/js/Pages/Production/Receipt/Detail.vue` — rincian per nota untuk rentang tanggal.
- `resources/js/i18n/catalogs/production.ts` — label tab, kolom, dan pesan kosong (id/en/ko).

**Definisi perhitungan (rentang `from`..`to`, inklusif, zona waktu sistem)**

- **Stok Awal** = seluruh Receive sebelum `from` − seluruh Issue sebelum `from`.
- **WIP & FG memakai model output** (baris milik mesin pembuat):
  - Receive = `production_results.qty_good` untuk part bertipe WIP atau FG, `result_date` dalam rentang, mesin = `production_results.machine_id`.
  - Issue = pemakaian part WIP oleh proses berikutnya (`work_order_consumptions`), diatribusikan kembali ke mesin pembuat melalui asal stok yang dikonsumsi: `work_order_consumptions.part_stock_id` → `production_results.output_part_stock_id` → `production_results.machine_id`. Bila asal stok tidak terlacak (data legacy), dipakai mesin pemakai dari `work_order_items.machine_id`.
  - FG: tidak punya issue, nilainya 0 sampai modul pengiriman FG ada.
- **Raw Material memakai model input** (baris milik mesin penerima/pemakai):
  - Receive = `production_material_receipts` dengan `transfer_status = transferred`, `received_at` dalam rentang.
  - Issue = `work_order_consumptions` pada mesin itu (`work_order_items.machine_id`).
- **Stok Akhir** = Stok Awal + Receive − Issue.
- Baris = kombinasi mesin × part × UOM. Baris ditampilkan bila Stok Awal ≠ 0 atau ada Receive/Issue dalam rentang.
- **Kategori tab**:
  - `wip_fg` = part dengan tipe WIP atau FG.
  - `material` = part dengan tipe selain WIP dan FG (MATERIAL dan yang belum terklasifikasi), agar tidak ada baris yang hilang.
- **Invoice/supplier**: Raw Material dari `material_issue_items` untuk penerimaan dan `work_order_consumptions` untuk pemakaian. WIP & FG dari konsumsi material utama pada production result terkait (item non-penunjang seperti OPP tape dikecualikan), konsisten dengan aturan label FG.

**Kontrak halaman**

- Route tetap `production-receipts.index` dan `production-receipts.detail`.
- Query string: `tab` (`wip_fg` | `material`), `from`, `to`, `machine_id` (opsional).
- Default: `tab=wip_fg`, `from` = tanggal 1 bulan berjalan, `to` = hari ini, `machine_id` = semua mesin.
- Halaman bersifat read-only; tidak ada aksi yang mengubah stok.

**Perubahan skema database:** tidak ada.

**Interaksi yang dipatok**

- Mengubah tab mempertahankan filter tanggal dan mesin.
- Mengubah filter langsung memuat ulang data (Inertia, preserve state).
- Baris dengan Stok Akhir negatif ditandai sebagai peringatan (perilaku yang sudah ada).
- Detail menampilkan tiga bagian terpisah (Stok Awal, Receive, Issue) dipecah per nota, dengan total dan Stok Akhir.

## Project Structure

    app/Services/WipInventoryService.php                  -> perhitungan kartu stok
    app/Http/Controllers/ProductionReceiptController.php  -> filter dan props halaman
    resources/js/Pages/Production/Receipt/Index.vue       -> dua tab + filter
    resources/js/Pages/Production/Receipt/Detail.vue      -> rincian per nota
    tests/Feature/WipInventoryTest.php                    -> regression kartu stok
    docs/prd/wip-inventory-stock-card.md                  -> PRD ini

## Commands

    Build: npm run build
    Test:  php artisan test --compact
    Lint:  vendor/bin/pint --dirty
    Dev:   composer run dev

## Testing Strategy

Test fitur Pest terhadap `WipInventoryService` dan halaman Inertia, mengikuti pola `tests/Feature/WipInventoryTest.php` yang sudah ada. Yang diuji adalah perilaku eksternal (angka dan prop halaman), bukan detail query:

- Stok awal membawa seluruh mutasi sebelum `from`.
- Receive dan Issue hanya menghitung mutasi di dalam rentang.
- Stok akhir = stok awal + receive − issue.
- Klasifikasi WIP/FG dan raw material terpisah sesuai tab.
- FG muncul sebagai Receive dari production result dengan Issue 0.
- Baris dengan stok awal saja tetap tampil.
- Filter mesin menyempitkan baris.
- Detail memecah angka per nota untuk rentang tanggal.

Test halaman memakai `assertInertia` untuk memastikan prop `rows`, `tab`, `from`, `to`, dan `machines` sesuai; prop snapshot (`stockRows`) tidak lagi dikirim.

## Boundaries

- **Always:** otorisasi lewat `Gate::authorize('viewAny', WorkOrder::class)`; jalankan `vendor/bin/pint --dirty`, `npm run build`, dan test terkait sebelum selesai; label UI dalam id/en/ko yang seimbang.
- **Ask first:** perubahan skema database, menambah dependency, mengubah definisi stok yang dipakai modul lain, atau menambah aksi yang menulis data dari halaman ini.
- **Never:** mengubah kuantitas `part_stocks` dari halaman laporan; mengarang data pengiriman FG; menghapus atau melemahkan test yang gagal tanpa persetujuan.

## Out of Scope

- Modul pengiriman/outgoing FG (sumber kolom Issue untuk FG).
- Kartu stok tingkat gudang (bukan per mesin).
- Tab snapshot "Stok WIP di Mesin" (dihapus dari halaman ini; data WIP fisik tetap tersedia di halaman Stock).
- Perubahan skema database atau tabel baru.
- Ekspor/PDF kartu stok.

## Success Criteria

- [ ] Halaman WIP Inventory hanya memiliki dua tab: **WIP & FG** dan **Raw Material**; tab snapshot tidak ada lagi.
- [ ] Kedua tab menampilkan kolom Stok Awal, Receive, Issue, Stok Akhir per mesin dan part.
- [ ] Filter rentang tanggal default tanggal 1 bulan berjalan s.d. hari ini, dan bisa diubah.
- [ ] Mengubah tab tidak menghapus filter tanggal/mesin.
- [ ] Baris WIP tampil di bawah mesin yang **memproduksinya**, bukan mesin yang memakainya.
- [ ] Baris FG yang diproduksi di rentang tampil dengan Receive = qty_good dan Issue = 0.
- [ ] Baris dengan Stok Awal ≠ 0 tetapi tanpa mutasi dalam rentang tetap tampil.
- [ ] Stok Akhir setiap baris = Stok Awal + Receive − Issue.
- [ ] Rincian per nota mengikuti rentang tanggal yang sama.
- [ ] `php artisan test --compact` hijau dan `npm run build` sukses.

## Open Questions

- **Sumber Issue untuk FG:** belum ada modul pengiriman/outgoing FG, sehingga Issue FG = 0. Perlu keputusan produk kapan modul pengiriman FG dibuat; jika dibuat, Issue FG diambil dari event pengiriman tanpa mengubah struktur tab.
- **Nomor nota untuk FG:** PRD ini memakai nota material utama dari production result. Jika operasional menginginkan nota pengiriman FG sendiri, itu ikut menunggu modul pengiriman.