# PRD: Redesign Form Receive Material (Multi-Packing Table & Otomasi Kedatangan)

## Problem Statement

Formulir penerimaan barang (Receive) saat ini belum sepenuhnya mencerminkan kebutuhan operasional penanganan kemasan bertingkat di lantai gudang, di mana satu wadah besar (Bundle/Pallet/Skid) sering kali memuat beberapa kemasan sub-unit fisik (misalnya: 1 Pallet berisi 3 Coil atau 5 Box) yang masing-masing memiliki nomor identifikasi individual (`TAG/1`, `TAG/2`), berat bersih, dan berat kotor yang harus dicatat secara rinci. Selain itu, beberapa data dokumen seperti No. AJU dan lokasi penerimaan default ("BULK AREA") belum terintegrasi secara otomatis dan seragam dari data kedatangan (Arrival) serta PO.

## Solution

Merombak total tampilan dan alur formulir Penerimaan Barang (`resources/js/Pages/Incoming/Receive/Form.vue`) mengikuti arsitektur modular pada referensi `assets/receive_form_manual.html`:

1. **Bagian 1 - Informasi Penerimaan:** Otomatis menarik data Part, Part Number, Material Group, Ukuran, dan No. PO dalam mode *readonly*. Mengizinkan input/update No. AJU, Tanggal Terima, Nomor Truk, dan Nama Driver. Mengunci lokasi penerimaan awal secara otomatis ke **"BULK AREA"**.
2. **Bagian 2 - Bundle & Packing:** Mengisi jumlah bundle dan tipe kemasan dari Arrival Item, menyediakan dropdown tipe bundle dan tipe packing dinamis dari master data sistem (bukan hardcoded).
3. **Bagian 3 - Detail Bundle (Tabel Dinamis Ber-rowspan):** Membentangkan baris tabel secara otomatis sesuai perkalian [Jumlah Bundle × Jumlah Packing]. Menampilkan kolom `No` dan `Tag #` (rowspan per bundle), nomor sub-packing otomatis `{TAG}/{1..N}`, serta kolom input per packing: `Qty/packing`, `UOM`, `Net weight (kg)`, dan `Gross weight (kg)`.
4. **Penyimpanan & Pelabelan:** Tag Bundle menjadi identitas utama stok (`incoming_receives` & `part_stocks`) agar konsisten dengan alur scan mobile APK, sementara rincian per-packing (`incoming_receive_packings`) disimpan utuh dan dicantumkan pada cetakan label material.

## User Stories

1. Sebagai staf gudang, saya ingin melihat data part, nomor part, kelompok material, dan nomor PO langsung terisi saat membuka form penerimaan, agar tidak perlu mengetik ulang data yang sudah ada di Arrival/PO.
2. Sebagai staf gudang, saya ingin lokasi penerimaan langsung terkunci default ke "BULK AREA", agar seluruh material masuk tertampung di area penampungan awal sebelum ditata ke rak.
3. Sebagai staf gudang, saya ingin mengisi No. AJU, nomor truk, dan nama pengemudi di form penerimaan, agar data surat jalan/pengiriman tercatat akurat.
4. Sebagai staf gudang, saya ingin menentukan jumlah bundle dan jumlah packing per bundle menggunakan data bawaan kedatangan dan pilihan dari master kemasan, agar struktur fisik barang langsung terbentuk.
5. Sebagai staf gudang, saya ingin tabel detail membentangkan baris sub-packing otomatis dengan penomoran `{TAG}/{1..N}` di bawah setiap baris Tag Bundle, agar saya bisa menginput berat bersih/kotor riil per kemasan sub-unit.
6. Sebagai operator mobile/produksi, saya ingin memindai Tag Bundle utama saat issue material ke mesin, dengan sistem tetap mencatat seluruh nomor packing bawaannya.
7. Sebagai petugas QC dan gudang, saya ingin cetakan label QR memuat ringkasan identitas bundle dan nomor sub-packing, agar ketertelusuran barang tetap terjaga saat dibongkar.

## Objective

Menghadirkan formulir penerimaan barang yang ergonomis, presisi, dan otomatis, di mana data dokumen terisi dari sumber PO/Arrival, pembagian fisik bundle dan sub-packing terdata detail dengan penomoran otomatis, serta stok barang terposting akurat di lokasi penampungan awal (BULK AREA).

## Technical Decisions

### 1. Struktur Database & Model

- **Tabel `incoming_receives`:** Menyimpan 1 baris per Tag Bundle (identitas stok utama). Menyimpan total `qty`, `net_weight`, `gross_weight`, `bundle_count` (=1), `bundle_unit`, `packing_count`, `packing_type`, `location_code` (default `BULK-AREA`), `truck_no`, dan metadata lainnya.
- **Tabel Baru `incoming_receive_packings`:**
  - `id`: primary key.
  - `receive_id`: foreign key ke `incoming_receives` (cascade delete).
  - `packing_no`: string (format `{TAG}/{sequence}`, misal `TAG-001/1`).
  - `sequence`: integer (1..N dalam bundle).
  - `qty`: decimal(20,4).
  - `uom`: string(20).
  - `net_weight`: decimal(20,4) nullable.
  - `gross_weight`: decimal(20,4) nullable.
  - `timestamps`.
- **Master Lokasi:** Menambahkan lokasi resmi `BULK-AREA` (nama: "Bulk Area", tipe: `storage`) melalui seeder `LocationSeeder` / `DeliveryLocationSeeder`.
- **Pembaruan No. AJU di Arrival:** Saat penerimaan disimpan, jika `no_aju` diisi/diubah di form receive, data tersebut disinkronkan kembali ke dokumen `incoming_arrivals.aju_no`.

### 2. Frontend Layout & Interaktivitas (`Form.vue`)

- **Section 1 (Informasi Penerimaan):**
  - Readonly: `Part Name`, `Part Number`, `Group / Ukuran`, `No. PO`, `Lokasi` (terisi "BULK AREA").
  - Editable: `Tanggal Terima` (default hari ini), `Truk #` (required), `Nama Driver`, `No. AJU`.
- **Section 2 (Bundle & Packing):**
  - `Jumlah bundle`: numeric input (otomatis mengambil dari `arrival_item.qty_bundle` jika ada).
  - `Tipe bundle`: select dropdown dari master `packingUnits` (mengambil dari `arrival_item.unit_bundle` jika ada).
  - `Jumlah packing`: numeric input (packing per bundle).
  - `Tipe packing`: select dropdown dari master `packingTypes` (COIL, SHEET, BOX, DRUM, PALLET, dsb.).
- **Section 3 (Detail Bundle Table):**
  - Penyesuaian baris dinamis (reactive): saat jumlah bundle atau packing diubah, tabel merender ulang baris tanpa menghilangkan tag/qty yang sudah diisi sebelumnya.
  - Kolom `No` dan `Tag #` menggunakan atribut `rowspan` setinggi jumlah packing dalam bundle tersebut.
  - Kolom `Packing #` otomatis menampilkan `{TAG}/{index}` secara real-time saat Tag # diketik.
  - Kolom input per packing: `Qty/packing`, `UOM` (dropdown dari master UOM), `Net weight (kg)`, `Gross weight (kg)`.

### 3. Posting Stok & Integrasi Label

- Posting stok ke `part_stocks` tetap dibuat per Tag Bundle dengan total akumulasi qty dan weight dari seluruh sub-packingnya, berlokasi di `BULK-AREA`.
- Template cetak label material (`receives.label`) mencantumkan rincian kemasan bertingkat dan daftar sub-packing bila ada.

## Project Structure

    database/migrations/xxxx_create_incoming_receive_packings_table.php -> tabel rincian sub-packing
    app/Models/IncomingReceive.php                                      -> relasi hasMany packings
    app/Models/IncomingReceivePacking.php                               -> model sub-packing
    app/Http/Controllers/ReceiveController.php                          -> controller create & store (validasi sub-packing & sync AJU)
    resources/js/Pages/Incoming/Receive/Form.vue                        -> UI redesign berbasis receive_form_manual.html
    resources/views/receives/label.blade.php                            -> template label cetak QR
    database/seeders/LocationSeeder.php                                 -> seeder lokasi BULK-AREA
    tests/Feature/TieredReceiveTest.php                                 -> test regresi sub-packing & form receive
    docs/prd/receive-form-redesign.md                                   -> PRD ini

## Commands

    Build: npm run build
    Test:  php artisan test --compact
    Lint:  vendor/bin/pint --dirty
    Dev:   composer run dev

## Testing Strategy

1. **Uji Validasi Form:**
   - Memastikan form menolak jika Truk # kosong.
   - Memastikan form menolak jika ada Tag Bundle yang belum diisi.
   - Memastikan penomoran sub-packing `{TAG}/{1..N}` terbentuk otomatis.
2. **Uji Penyimpanan & Relasi Database:**
   - Memastikan data `incoming_receives` tersimpan per Tag Bundle dengan total akumulasi qty dan net weight yang benar.
   - Memastikan tabel `incoming_receive_packings` terisi tepat sebanyak [Jumlah Bundle × Jumlah Packing].
   - Memastikan lokasi receive tersimpan sebagai `BULK-AREA`.
   - Memastikan nomor AJU tersinkron ke dokumen `incoming_arrivals`.
3. **Uji Konsistensi Stok:**
   - Memastikan `part_stocks` menerima penambahan stok sesuai total per Tag Bundle di lokasi `BULK-AREA`.

## Boundaries

- **Always:** Otorisasi melalui `Gate::authorize('create', IncomingReceive::class)`; validasi satuan UOM terhadap `UomCatalog`; validasi total receive tidak melebihi sisa PO/Arrival; format PHP mengikuti Pint.
- **Ask first:** Mengubah struktur barcode QR scanner di APK mobile atau menghapus kolom legacy yang masih dipakai.
- **Never:** Menyimpan dummy data yang di-hardcode dari template HTML tanpa referensi database; menghapus riwayat receive yang sudah terdistribusi ke stok.

## Out of Scope

- Penimbangan otomatis via timbangan digital IoT (input berat tetap manual oleh operator).
- Integrasi ke modul penerimaan retur barang produksi.

## Success Criteria

- [ ] Formulir Receive (`Incoming/Receive/Form.vue`) mengadopsi struktur 3 section dan tabel ber-rowspan dari `assets/receive_form_manual.html`.
- [ ] Field Part, Part #, Group/Ukuran, No. PO terisi otomatis dan readonly; Lokasi default terkunci ke `BULK-AREA`.
- [ ] No. AJU, Truk #, Nama Driver dapat diisi di form receive dan No. AJU tersimpan ke tabel arrival.
- [ ] Pilihan Tipe Bundle dan Tipe Packing berasal dari master data sistem, dengan default dari data Arrival Item.
- [ ] Tabel detail merender baris sub-packing dinamis dengan kode `{TAG}/{index}` otomatis dan input Qty, UOM, Net Weight, Gross Weight per packing.
- [ ] Data tersimpan ke `incoming_receives` (per tag bundle) dan `incoming_receive_packings` (per sub-packing).
- [ ] Seluruh automated test hijau (`php artisan test`) dan `npm run build` sukses tanpa error.
