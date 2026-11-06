# PRD: Incoming Supplier-PO Filter & Work Order BOM Process Material Breakdown

## Problem Statement

Di area **Incoming**, operator gudang kesulitan memilih Purchase Order (PO) pada Form Arrival karena dropdown menampilkan seluruh PO dari berbagai supplier sekaligus, termasuk PO yang seluruh barangnya sudah selesai diterima. Selain itu, status pipeline antara kedatangan barang, inspeksi kualitas, dan penerimaan fisik (`Arrival` -> `IQC Cek` -> `Received`) perlu ditampilkan secara konsisten dan transparan.

Di area **Produksi**, halaman Detail Work Order (`resources/js/Pages/Production/WorkOrder/Show.vue`) menggunakan pengelompokan baris lama yang menyederhanakan proses secara agresif (`head(g)`). Akibatnya, pada proses perakitan (Assembly) yang membutuhkan banyak komponen sekaligus (misalnya proses Assembly Full yang memerlukan 4 material: gabungan WIP proses sebelumnya dan komponen/material langsung), sistem hanya menampilkan 1 material pertama dan menyembunyikan komponen lainnya. Selain itu, operator membutuhkan kejelasan bahwa sebelum material dikeluarkan, sistem menampilkan acuan **Main Part BOM**, dan setelah material di-issue, sistem menampilkan **Part Substitute** (material riil) yang dialokasikan.

## Solution

1. **Incoming:**
   - Menyediakan filter berjenjang di form Kedatangan (Arrival): Pengguna memilih **Supplier** terlebih dahulu, kemudian dropdown PO otomatis terfilter hanya menampilkan PO aktif milik supplier tersebut yang masih memiliki sisa kuantitas barang yang belum tiba (`remaining_qty > 0`).
   - Menyinkronkan dan memperjelas tahapan status di daftar/dokumen: `Arrival` (Tiba), `IQC Cek` (Pemeriksaan Kualitas), dan `Received` (Diterima ke Stok Gudang).

2. **Produksi & Work Order (BOM Breakdown per Proses):**
   - Merombak visualisasi routing pada halaman Detail Work Order menjadi pengelompokan per proses kerja:
     - **Mesin Non-TPL (Assembly, Welding, Stamping tunggal, dll.):** Setiap baris proses menampilkan **seluruh item input** yang masuk ke proses tersebut (baik Raw Material maupun WIP child hasil proses sebelumnya) secara transparan lengkap dengan Part Number, nama, kebutuhan (`qty_required`), dan realisasi konsumsi (`qty_consumed`). Tidak ada material yang disembunyikan.
     - **Mesin Khusus TPL Press Line (`TPL DONGSHIN` & `TPL KUKIL`):** Mempertahankan aturan continuous tandem line, yaitu menampilkan input Raw Material awal di stasiun pertama menuju ke output WIP akhir (WIP intermediate tidak ditampilkan sebagai stok/input terpisah).
   - Mengimplementasikan logika tampilan material dinamis:
     - **Sebelum di-issue (Status `planned` / belum scan):** Kolom material menampilkan **Main Part BOM** sebagai acuan standar resep produksi.
     - **Setelah di-issue (Status `in_progress` / sudah ada slip issue & booking/scan):** Kolom material beralih menampilkan **Part Substitute** riil yang dialokasikan beserta identitas tag/part fisiknya, dengan badge/penanda bahwa part tersebut merupakan substitusi aktif dari Main Part.

## User Stories

1. As a staff Gudang Incoming, I want memilih Supplier terlebih dahulu pada Form Kedatangan, so that daftar dropdown PO hanya memuat PO yang relevan milik supplier tersebut.
2. As a staff Gudang Incoming, I want dropdown PO hanya menampilkan PO yang belum tuntas kedatangannya (`remaining_qty > 0`), so that saya tidak keliru memilih PO yang sudah selesai.
3. As a staff Gudang & QC, I want melihat indikator status yang jelas (`Arrival` -> `IQC Cek` -> `Received`), so that alur penerimaan barang dapat dipantau tahapannya dengan pasti.
4. As a supervisor PPIC / Produksi, I want melihat seluruh material input pada proses Assembly di halaman Work Order, so that seluruh komponen (misal 4 material) terpampang nyata dan tidak terpotong.
5. As a operator / leader produksi, I want melihat acuan Main Part BOM sebelum material di-issue, so that saya mengetahui spesifikasi standar komponen yang dibutuhkan.
6. As a operator / leader produksi, I want melihat Part Substitute aktual setelah material di-issue, so that saya mengetahui persis lot/substitusi part mana yang sedang dipasang di lini produksi.
7. As a sistem dan tim TPL, I want mesin TPL tetap diperlakukan sebagai continuous tandem line, so that integritas pelaporan single-output WIP akhir di mobile APK tidak terganggu.

## Objective

Menghilangkan potensi kesalahan input PO di gudang incoming dengan filter berjenjang berbasis supplier dan sisa qty, serta memberikan transparansi 100% terhadap komponen material per proses kerja di Work Order (menampilkan seluruh input proses perakitan, membedakan TPL vs Non-TPL, dan menampilkan transisi Main Part -> Substitute setelah issue).

## Technical Decisions

### 1. Modul Incoming: Filter Supplier -> PO & Pipeline Status
- **Controller & Endpoint:**
  - `IncomingArrivalController::create` / `edit`: Mengirimkan daftar PO yang sudah difilter atau menyediakan opsi filter client-side terstruktur di mana PO dihitung berdasarkan `supplier_id` dan `remaining_qty > 0`.
  - Penentuan `remaining_qty` PO: `po_items.qty - SUM(incoming_arrival_items.qty_goods) > 0`. PO dengan sisa `<= 0` atau status `completed`/`cancelled` dikeluarkan dari dropdown form baru.
- **Frontend Arrival Form (`Incoming/Arrival/Form.vue`):**
  - Urutan field diatur: Input **Supplier** berada di posisi utama/atas sebelum **Purchase Order**.
  - Dropdown PO reaktif: Mengikat ke `form.supplier_id`. Jika supplier diubah, opsi PO langsung disaring, dan field PO di-reset jika tidak sesuai dengan supplier baru.
  - Kolom status pipeline di Index Arrival dan Show: Mengelompokkan status menjadi:
    - `Arrival`: Baru dicatat, belum dilakukan/lulus pemeriksaan kualitas.
    - `IQC Cek`: Masuk antrean inspeksi kualitas (`pending` / `in_review` / `rejected`).
    - `Received`: Material telah lulus QC (`iqc_status = passed`) dan selesai di-receive ke stok fisik gudang.

### 2. Modul Produksi: Work Order Process Grouping & Multi-Input Breakdown
- **Frontend Work Order Show (`resources/js/Pages/Production/WorkOrder/Show.vue`):**
  - Mengubah komputasi `routingGroups`:
    - Memeriksa apakah mesin termasuk TPL Press Line (`MachineRouting::isTplPressLine` atau nama berawalan `TPL DONGSHIN` / `TPL KUKIL`).
    - **Jika Mesin TPL:** Tetap menggabungkan sequence internal menjadi satu baris tandem (input: child_part awal, output: parent_part akhir).
    - **Jika Mesin Non-TPL (Assembly, Welding, Stamping biasa):** Dikelompokkan berdasarkan unit proses/mesin yang sama. Namun, seluruh item input yang masuk ke proses tersebut dirender sebagai sub-baris atau multi-row di dalam blok proses tersebut (misal untuk Assembly 4 komponen, keempatnya dirender dengan part number, nama part, qty required, dan qty consumed masing-masing).
- **Logika Tampilan Part (Main Part vs Substitute Part):**
  - Pada setiap item kebutuhan proses:
    - Cek apakah item sudah memiliki relasi issue (`work_order_consumptions` atau `allocations` atau status WO `in_progress`/`completed` dengan `selected_part_id != child_part_id`).
    - **Belum di-issue:** Tampilkan `child_part.part_number` & `child_part.part_name` (Label: `Main BOM`).
    - **Sudah di-issue:** Tampilkan part aktual dari `selected_part` / `allocations` / konsumsi aktual. Sertakan label badge subtitusi bila part number berbeda dengan Main BOM.

## Project Structure

    app/
      Http/Controllers/
        IncomingArrivalController.php   -> Penyediaan data PO berfilter supplier & sisa kuantitas
        WorkOrderController.php         -> Eager loading lengkap item, part, allocations, dan substitute
      Services/
        WoService.php                   -> Snapshot WO items dan grouping helper
      Support/
        MachineRouting.php              -> Deteksi mesin TPL vs mesin reguler
    resources/
      js/
        Pages/
          Incoming/
            Arrival/Form.vue            -> Filter Supplier -> PO aktif belum tiba
            Arrival/Index.vue           -> Status pipeline Arrival -> IQC Cek -> Received
          Production/
            WorkOrder/Show.vue          -> Tabel alur proses multi-input material & badge Main vs Substitute
    tests/
      Feature/
        IncomingArrivalPoFilterTest.php -> Pengujian filter PO per supplier dan sisa kuantitas
        WorkOrderProcessBOMTest.php     -> Pengujian visualisasi multi-material per proses dan status substitute

## Commands

    Build: npm run build
    Test:  vendor/bin/pest
    Lint:  vendor/bin/pint --format agent
    Dev:   npm run dev

## Testing Strategy

- **Feature Test (Pest):**
  - Uji Form Arrival: Memastikan hanya PO milik supplier terpilih dengan `remaining_qty > 0` yang dapat dipilih. PO dari supplier lain atau yang sudah terpenuhi 100% tidak muncul.
  - Uji Work Order BOM Snapshot: Membuat WO untuk part FG dengan proses Assembly yang memiliki 4 material input (2 child WIP + 2 raw material). Memastikan keempat item tersimpan di `work_order_items`.
  - Uji Tampilan Status Part: Memastikan sebelum issue part yang tampil adalah Main BOM, dan setelah release/issue dengan part pengganti, part yang tampil adalah Substitute.
  - Uji Garansi TPL: Memastikan mesin TPL tidak terpecah menjadi multi-baris di APK maupun Web.

## Boundaries

- **Always:**
  - Jalankan `vendor/bin/pint --format agent` dan `npm run build` setelah mengubah file PHP dan Vue.
  - Pertahankan konsistensi i18n (`id`, `en`, `ko`) pada setiap teks label atau status baru.
  - Jaga agar mesin `TPL DONGSHIN` dan `TPL KUKIL` tetap berjalan sebagai continuous tandem line.
- **Ask first:**
  - Perubahan struktur skema tabel database yang dapat memengaruhi data historis WO atau Arrival.
- **Never:**
  - Mengubah acuan dasar stok fisik di luar mekanisme FIFO booking yang sudah ada.
  - Menghilangkan child part WIP intermediate pada proses Non-TPL Assembly.

## Out of Scope

- Merombak skema database `boms` atau `bom_items` dari nol (kita memanfaatkan snapshot relasi yang sudah ada).
- Mengubah alur pelaporan mobile APK untuk TPL (APK sudah solid dan terkunci pada final WIP).

## Success Criteria

- [ ] Pada Form Arrival, memilih Supplier membatasi opsi PO hanya untuk supplier tersebut.
- [ ] PO yang seluruh itemnya sudah 100% diterima tidak lagi muncul di dropdown Arrival baru.
- [ ] Status kedatangan di tabel Index Arrival merefleksikan alur bertahap: `Arrival`, `IQC Cek`, dan `Received`.
- [ ] Halaman Work Order Show menampilkan seluruh material input pada proses Assembly (tidak ada material yang hilang/tersembunyi).
- [ ] Sebelum issue, baris material menampilkan Main Part BOM; setelah issue, menampilkan Part Substitute yang teralokasi.
- [ ] Mesin TPL tetap terkonsolidasi sebagai satu continuous line tanpa memecah intermediate WIP.
- [ ] Seluruh test Pest lulus hijau (`vendor/bin/pest`) dan `npm run build` berjalan tanpa error.

## Open Questions

*Tidak ada open questions yang memblokir. Seluruh boundary teknis telah terdefinisi.*
