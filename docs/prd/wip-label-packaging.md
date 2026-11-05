# PRD: WIP Label Packaging & Multi-Container Printing

Module id: `wip-label-packaging`
Capability map: `docs/prd/wip-label-packaging.md` (dokumen ini)

## Problem Statement

Di lantai produksi, barang hasil pengerjaan mesin (WIP) tidak diletakkan dalam satu tumpukan besar, melainkan dibagi ke beberapa wadah fisik seperti **Troli, Box, atau Pallet** dengan kapasitas tertentu (misal: 1 box kapasitas 100 pcs).

Saat ini, sistem antrean cetak label WIP di Web ERP hanya mencetak **satu lembar label gelondongan** untuk seluruh total kuantitas dalam satu periode (misal: 1 lembar bertuliskan 352 pcs). Akibatnya:
1. Wadah fisik lainnya tidak memiliki label barcode penanda identitas.
2. Jika ada sisa ganjil yang tidak memenuhi kapasitas satu wadah (misal: 352 pcs diisi ke box kapasitas 100 pcs menghasilkan sisa 52 pcs), petugas tidak memiliki cara untuk menunda pelabelan sisa tersebut agar bisa digabungkan dengan hasil periode jam berikutnya.
3. Kotak kanan bawah pada template label masih bertuliskan `# LOCATION #`, padahal petugas di lantai membutuhkan nomor urut label/kontainer (misal: `LABEL 1 / 4`, `LABEL 2 / 4`).
4. Pencetakan label reject/NG dilakukan per periode jam yang sering kali hanya menghasilkan 1-3 pcs, padahal barang reject biasanya dikumpulkan hingga akhir shift atau akhir Work Order sebelum dilabeli sekaligus.

## Solution

Sebelum mencetak label WIP di Web ERP, sistem menampilkan modal dialog interaktif untuk mengatur pembagian kemasan:
1. **Input Jenis Kemasan (`Packing Type`):** Dropdown pilihan `Troli`, `Box`, atau `Pallet`.
2. **Input Kapasitas per Kemasan (`Packing Qty`):** Angka kapasitas per kemasan dalam satuan EA/PCS.
3. **Kalkulasi & Konfirmasi Instan:** Sistem langsung menghitung jumlah kemasan penuh dan sisa ganjil yang belum memenuhi kemasan.
4. **Fleksibilitas Sisa Ganjil:** Petugas dapat memilih lewat checkbox apakah sisa ganjil dicetak labelnya sekarang (sebagai label sisa), atau ditunda (di-carry-over) untuk diakumulasikan ke pencetakan periode jam berikutnya pada part yang sama.
5. **Cetak Multi-Label Berurutan:** Halaman cetak otomatis menghasilkan sejumlah lembar label sesuai hasil kalkulasi (misal 3 atau 4 lembar), di mana kotak kanan bawah mencantumkan nomor label (`LABEL 1 / 4`, `LABEL 2 / 4`), jenis kemasan tercantum jelas, dan baris `TAG #` mengacu ke nomor tag invoice material asal.
6. **Cetak Label NG Akumulatif:** Menyediakan opsi cetak label NG yang dapat diakumulasikan di akhir shift atau akhir Work Order.

## User Stories

1. Sebagai petugas gudang/leader, saya ingin memilih jenis kemasan (Troli, Box, Pallet) sebelum mencetak label, agar identitas wadah fisik tercatat sesuai kenyataan di lapangan.
2. Sebagai petugas gudang/leader, saya ingin menentukan kapasitas per kemasan (misal 100 pcs), agar sistem memecah kuantitas periode menjadi lembaran label sesuai jumlah wadah fisik.
3. Sebagai petugas gudang/leader, saya ingin melihat rincian jumlah kemasan penuh dan sisa ganjil sebelum mencetak, agar saya tahu pasti berapa label yang akan keluar dari printer.
4. Sebagai petugas gudang/leader, saya ingin memiliki opsi untuk menunda pencetakan label untuk sisa ganjil, agar sisa barang tersebut bisa digabungkan ke box penuh pada periode berikutnya tanpa membuang kertas label.
5. Sebagai petugas gudang/leader, saya ingin nomor urut label (`LABEL X / Y`) tercetak di kotak kanan bawah, agar operator di mesin berikutnya tahu urutan wadah yang mereka terima.
6. Sebagai operator/QC, saya ingin nomor tag pada label WIP mengacu ke nomor tag invoice material asal, agar ketertelusuran bahan baku tetap terjaga.
7. Sebagai petugas gudang/QC, saya ingin mencetak label NG secara akumulatif di akhir shift atau akhir WO, agar keranjang barang reject tidak dipenuhi kertas label pecahan per jam.

## Objective

Menghubungkan pencatatan produksi per jam di sistem dengan manajemen wadah fisik (Troli/Box/Pallet) di lantai pabrik, memastikan setiap wadah memiliki label fisiknya masing-masing tanpa mengubah keakuratan angka riil produksi operator.

Pengguna: Petugas Gudang dan Leader Produksi pencetak label di Web ERP.

Ukuran keberhasilan: Petugas dapat menginput packing type dan packing qty sebelum cetak, sistem mencetak multi-label dengan nomor urut kontainer yang presisi, sisa ganjil dapat ditunda ke periode berikutnya secara akurat, dan label NG dapat dicetak akumulatif di akhir shift/WO.

## Technical Decisions

### 1. Parameter Cetak via Modal Dialog di Frontend

- Tombol `Print Good` pada tabel antrean label WIP (`resources/js/Pages/Production/Label/Index.vue`) tidak langsung membuka tab cetak, melainkan membuka Modal Dialog:
  - Dropdown `Packing Type`: `Troli`, `Box`, `Pallet`.
  - Input `Packing Qty (EA)`: Numeric integer > 0.
  - Kartu Ringkasan Kalkulasi:
    - `X kemasan penuh (@ Y EA)`
    - `Sisa ganjil: Z EA`
  - Checkbox Sisa Ganjil: `[✓] Cetak label untuk sisa Z EA sekarang` (Total: `X + 1` label) vs `[ ] Tunda sisa Z EA ke periode berikutnya` (Total: `X` label).
  - Konfirmasi notifikasi jumlah label yang akan dicetak.
- Setelah tombol "Cetak" di modal dikonfirmasi, sistem membuka tab baru ke route cetak dengan parameter yang dipilih:
  `route('production-labels.print', [result.id, 'good'])` + query parameters:
  `packing_type`, `packing_qty`, `include_remainder`.

### 2. Penanganan Carry-Over Sisa Ganjil

- Angka riil hasil produksi operator di `production_results.qty_good` **tidak diubah** (misal tetap 352 pcs).
- Ditambahkan kolom pelacak sisa label di tabel `production_results`:
  - `label_carried_over_qty` (decimal, default 0) untuk mencatat kuantitas yang ditunda pelabelannya.
  - `label_carry_over_source_id` (nullable foreign id ke `production_results`) untuk mencatat sumber gabungan sisa dari periode sebelumnya.
- Jika periode berikutnya untuk part & WO yang sama dilaporkan, antrean cetak menampilkan sisa yang belum berlabel dari periode sebelumnya sehingga dapat digabungkan ke perhitungan kemasan periode baru.

### 3. Penataan Template Label Fisik (`wip.blade.php`)

- **Kotak Kanan Bawah:** Menggantikan teks statis `# LOCATION #` menjadi nomor urut label:
  `LABEL 1 / 4`, `LABEL 2 / 4`, `LABEL 3 / 4`, `LABEL 4 / 4`.
- **Quantity:** Setiap lembar label memuat quantity spesifik wadah tersebut (misal label 1-3 memuat `100 EA`, label 4 memuat `52 EA`).
- **Jenis Kemasan:** Dicantumkan pada header atau sub-label quantity (misal: `QUANTITY (EA - BOX)`).
- **Baris `TAG #`:** Tetap mengacu ke nomor tag/batch invoice material asal yang dikonsumsi.
- **Halaman Cetak CSS:** Menggunakan `@page { size: 150mm 100mm; margin: 0; }` dan `.label-card { page-break-after: always; }` sehingga browser langsung mencetak seluruh lembar label wadah dalam satu kali perintah print.

### 4. Cetak Akumulatif Label NG (Akhir Shift / Akhir WO)

- Pada halaman antrean label WIP disediakan tombol/aksi **"Cetak Akumulasi NG"** yang memungkinkan petugas memilih filter Shift atau Work Order untuk menggabungkan seluruh kuantitas reject yang belum dicetak menjadi satu label NG akhir.

## Project Structure

    app/Http/Controllers/ProductionLabelController.php  -> Validasi parameter kemasan, kalkulasi multi-label, dan akumulasi NG
    app/Models/ProductionResult.php                     -> Relasi carry-over dan penanda status label
    database/migrations/                                -> Kolom pelacak carry-over sisa label
    resources/js/Pages/Production/Label/Index.vue       -> Modal dialog packing type, packing qty, dan konfirmasi label
    resources/views/production/labels/wip.blade.php      -> Template multi-page label per wadah dengan nomor urut LABEL X / Y
    tests/Feature/ProductionLabelQueueTest.php           -> Test kalkulasi kemasan, penundaan sisa ganjil, dan akumulasi NG

## Commands

    Test:    ./vendor/bin/pest tests/Feature/ProductionLabelQueueTest.php
    Full:    ./vendor/bin/pest
    Lint:    vendor/bin/pint --dirty --format agent
    Build:   npm run build

## Testing Strategy

- **Feature Test (Pest):**
  - Kalkulasi kemasan pas (misal 300 pcs @ 100 = tepat 3 label).
  - Kalkulasi sisa ganjil dicetak (352 pcs @ 100 dengan sisa dicetak = 4 label: 3x 100 EA + 1x 52 EA).
  - Kalkulasi sisa ganjil ditunda (352 pcs @ 100 dengan sisa ditunda = 3 label @ 100 EA, sisa 52 pcs tercatat carry-over).
  - Penggabungan carry-over ke periode berikutnya.
  - Format penomoran `LABEL 1 / 4` sampai `LABEL 4 / 4` tercantum di view cetak.
  - Akumulasi label NG per Shift / WO.

## Boundaries

- **Always:** Gunakan satuan master part (EA/PCS); simpan riwayat cetak; pastikan seluruh lembar label dapat dicetak dalam satu dialog browser print.
- **Ask first:** Perubahan ukuran fisik stiker label (ukuran standar saat ini: 150mm x 100mm); penambahan jenis kemasan baru di luar Troli, Box, Pallet.
- **Never:** Mengubah atau mengurangi nilai kuantitas riil hasil produksi operator di tabel `production_results` hanya demi menyesuaikan kemasan label.

## Out of Scope

- Pencetakan label langsung dari printer thermal bluetooth di HP/APK (tetap dicetak via browser Web ERP).
- Sensor berat otomatis pada troli/pallet.

## Success Criteria

- [ ] Modal dialog muncul saat tombol cetak Good diklik, memuat pilihan kemasan (Troli, Box, Pallet) dan input kapasitas per kemasan.
- [ ] Sistem menampilkan kalkulasi instan jumlah kemasan penuh dan sisa ganjil.
- [ ] Checkbox sisa ganjil berfungsi: jika dicetak menghasilkan label tambahan sejumlah sisa; jika ditunda kuantitas sisa dicatat carry-over.
- [ ] Template label mencetak seluruh lembaran dengan nomor urut `LABEL 1 / N` di kotak kanan bawah.
- [ ] Baris `TAG #` mengacu ke tag invoice asal dan jenis kemasan tercantum di label.
- [ ] Fitur cetak akumulasi NG tersedia di akhir shift / akhir WO.
- [ ] Seluruh pengujian otomatis di `ProductionLabelQueueTest` lulus hijau.
