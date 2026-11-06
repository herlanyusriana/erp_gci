# PRD: Unified Purchase Order, Streamlined Local Arrival, and Incoming Quality (IQC Pass)

Module id: `unified-po-arrival-iqc`
Capability map: `docs/prd/unified-po-arrival-iqc.md` (dokumen ini)

## Problem Statement

Saat ini di Web ERP terdapat pemisahan menu yang membingungkan dan tidak efektif antara **Purchase Order** (impor saja) dan **Local PO** (khusus lokal). Alur Local PO lama menggabungkan pembuatan PO dan kedatangan sekaligus dalam satu tabel, padahal dalam kenyataan operasional:
1. Bagian Purchasing membuat PO terlebih dahulu (baik untuk supplier lokal maupun impor).
2. Ketika truk pengiriman tiba di pabrik, staf gudang baru mencatat kedatangan fisik (Arrival). Memaksa kedatangan lokal mengikuti form impor terlalu rumit karena memuat kolom kapal/vessel, kontainer, seal code, dan bill of lading yang tidak ada pada pengiriman lokal.
3. Belum ada gerbang kendali mutu material masuk (Incoming Quality Control / IQC) sebelum barang dibuatkan label barcode dan dipakai produksi, sehingga tim QC tidak memiliki tempat resmi untuk menandai material yang sudah lolos uji.

## Solution

1. **Unifikasi Menu Purchase Order:**
   - Menghapus menu lama `Local PO`.
   - Menu **Purchase Order** (`/purchase-orders`) menjadi satu-satunya tempat pembuatan PO, mendukung seluruh supplier (Lokal maupun Impor).
   - Validasi part mengacu pada substitute aktif supplier yang dipilih.

2. **Penyederhanaan Alur Kedatangan (Arrival):**
   - Menu **Kedatangan (Arrival)** (`/incoming-arrivals`) menyediakan pemilih jenis kedatangan: **`[ Lokal ]`** vs **`[ Impor ]`**.
   - Pengguna memilih PO yang sudah dibuat oleh Purchasing $\rightarrow$ daftar item barang otomatis terisi (*auto-populate*).
   - **Kedatangan Parsial:** Pengguna dapat menyesuaikan kuantitas datang jika pengiriman dilakukan bertahap (sisa PO tercatat otomatis).
   - **Form Kedatangan Lokal:** Disederhanakan hanya memuat: Tanggal Kedatangan, Nama Supplier, Nomor PO, No. Surat Jalan / Invoice, No. PEN, No. AJU, dan daftar item (Part, Qty, Satuan, Net Weight jika basis berat). Tanpa atribut kontainer, seal code, kapal/vessel, pelabuhan, atau bill of lading.
   - **Form Kedatangan Impor:** Tetap mempertahankan atribut lengkap kepabeanan impor (kapal, kontainer, dsb).

3. **Menu Baru: Incoming Quality (`/incoming-quality`):**
   - Berada di bawah modul **Quality Control**.
   - Menampilkan tabel ringkas sesuai arahan operasional:
     `Tanggal Arrival | Supp Name | PO No | Item List | Ceklis IQC Pass`.
   - Cukup satu klik ceklis untuk menandai kedatangan berstatus **IQC Pass**, membuka kunci agar material siap di-receive dan dicetak label barcodenya oleh gudang.

## User Stories

1. Sebagai staf Purchasing, saya ingin membuat PO untuk supplier lokal maupun impor di satu menu Purchase Order, agar saya tidak perlu berpindah-pindah menu yang membingungkan.
2. Sebagai staf Gudang, saya ingin memilih jenis kedatangan `Lokal` atau `Impor` di menu Arrival, agar form yang tampil sesuai dengan jenis dokumen yang saya pegang.
3. Sebagai staf Gudang, saya ingin memilih nomor PO dan mendapati seluruh itemnya otomatis terisi, agar saya tidak perlu mengetik ulang daftar part dan spesifikasinya.
4. Sebagai staf Gudang, saya ingin dapat mengedit kuantitas barang datang saat kiriman datang bertahap (parsial), agar catatan kedatangan riil sesuai dengan fisik yang turun dari truk.
5. Sebagai staf Gudang, saya ingin mengisi kedatangan lokal secara ringkas (hanya No. Surat Jalan / Invoice, No. PEN, No. AJU, dan item), agar proses bongkar muat lokal tidak terhambat input data yang tidak relevan.
6. Sebagai staf QC, saya ingin melihat daftar kedatangan material di menu `Incoming Quality` dengan kolom `Tanggal Arrival | Supp Name | PO No | Item List`, agar saya tahu material apa saja yang menunggu pemeriksaan.
7. Sebagai staf QC, saya ingin memberikan persetujuan dengan cukup mencentang `IQC Pass`, agar status lolos uji tercatat secara resmi dan instan di sistem.
8. Sebagai staf Gudang/Penerimaan, saya ingin fitur Receive (cetak label barcode material) hanya aktif setelah material berstatus `IQC Pass`, agar material yang belum diperiksa QC tidak lolos ke lantai produksi.

## Objective

Menyederhanakan alur pengadaan dan penerimaan barang dengan menyatukan PO, merampingkan input kedatangan lokal berbasis auto-populate dari PO, dan menyediakan gerbang mutu material masuk (IQC Pass) yang praktis dan terintegrasi.

Pengguna: Tim Purchasing, Tim Gudang (Inbound/Receive), dan Tim Quality Control (QC).

Ukuran keberhasilan: Menu `Local PO` dihapus, PO lokal & impor dibuat di satu menu, form kedatangan lokal otomatis terisi saat pilih PO dan bebas dari atribut kontainer/kapal, menu `Incoming Quality` aktif dengan fitur ceklis `IQC Pass`, dan alur Receive gudang terikat pada kelolosan IQC.

## Technical Decisions

### 1. Perubahan Struktur Menu & Route
- **Hapus:** Menu dan route `local-pos` (`LocalPoController`, views `resources/js/Pages/Incoming/LocalPo/*`).
- **Update Purchase Order:** Hapus filter pembatas `where('source', 'import')` pada `PurchaseOrderController` sehingga dropdown supplier memuat seluruh supplier (Lokal & Impor). Form PO mendukung pemilihan part substitute aktif dari supplier lokal maupun impor.
- **Update Incoming Arrival:**
  - Tambahkan switcher tab `type: 'local' | 'import'` di form pembuatan Arrival.
  - Tambahkan endpoint/relasi untuk mengambil item dari PO terpilih guna auto-populate ke tabel item kedatangan.
  - Form Arrival lokal menyembunyikan panel kontainer, kapal, dan dokumen kepabeanan impor, serta menampilkan input `No. Surat Jalan / Invoice`, `No. PEN`, dan `No. AJU`.
- **Modul Baru Incoming Quality:**
  - Route: `GET /incoming-quality`, `POST /incoming-quality/{arrival}/pass`.
  - Controller: `App\Http\Controllers\IncomingQualityController`.
  - View: `resources/js/Pages/Quality/Incoming/Index.vue`.
  - Kolom tabel: Tanggal Arrival, Supplier, Nomor PO, Item List (ringkasan part & kuantitas), dan tombol/ceklis `IQC Pass`.

### 2. Skema Database
- Tabel `incoming_arrivals`:
  - Kolom `iqc_passed_at` (nullable timestamp).
  - Kolom `iqc_passed_by` (nullable foreignId ke `users`).
  - Kolom `iqc_status` (string, default 'pending', values: 'pending', 'passed', 'rejected').
  - Kolom `delivery_note_no` / alias untuk No. Surat Jalan / Invoice lokal.
- Membersihkan data lama `is_local` sesuai keputusan Q4=B.

### 3. Keterkaitan dengan Penerimaan (Receive & Cetak Label)
- Pada `ReceiveController` (alur pembuatan TAG barcode material):
  - Tambahkan pengecekan otorisasi/validasi: kedatangan harus berstatus `iqc_status = 'passed'` sebelum dapat di-receive.
  - Tampilkan penanda visual/badge `IQC Pass` di tabel daftar Receive.

## Project Structure

    app/Http/Controllers/PurchaseOrderController.php       -> Unifikasi PO lokal & impor
    app/Http/Controllers/IncomingArrivalController.php     -> Tab Lokal vs Impor, auto-populate PO, form ringkas lokal
    app/Http/Controllers/IncomingQualityController.php     -> Controller baru untuk ceklis IQC Pass
    app/Http/Controllers/ReceiveController.php             -> Guard status IQC Pass sebelum receive
    resources/js/Pages/Incoming/PurchaseOrder/Form.vue     -> Form PO universal (support lokal & impor)
    resources/js/Pages/Incoming/Arrival/Form.vue           -> Form Arrival terintegrasi switcher Lokal/Impor + auto-fill PO
    resources/js/Pages/Quality/Incoming/Index.vue          -> Halaman Incoming Quality (tabel ringkas + ceklis IQC Pass)
    resources/js/Pages/Incoming/ModuleLauncher.vue         -> Hapus tile Local PO, sisakan Purchase Order
    resources/js/Pages/Quality/ModuleLauncher.vue          -> Tambahkan tile Incoming Quality

## Commands

    Test:    ./vendor/bin/pest tests/Feature/IncomingPoArrivalTest.php
    Full:    ./vendor/bin/pest
    Lint:    vendor/bin/pint --dirty --format agent
    Build:   npm run build

## Testing Strategy

- **Feature Test (Pest):**
  - Membuat PO dengan supplier lokal dan supplier impor di menu PO yang sama.
  - Membuat Arrival lokal dengan memilih PO lokal $\rightarrow$ item terisi otomatis $\rightarrow$ simpan dengan nomor PEN, AJU, dan Surat Jalan.
  - Menguji kedatangan parsial (kuantitas datang diedit lebih kecil dari PO, sisa PO terhitung benar).
  - Verifikasi menu Incoming Quality menampilkan kedatangan yang menunggu uji.
  - Menguji tombol ceklis `IQC Pass` berhasil mengupdate `iqc_status = 'passed'` dan mencatat user penguji.
  - Memverifikasi Receive gudang tertolak/terkunci jika `iqc_status` belum 'passed', dan sukses setelah 'passed'.

## Boundaries

- **Always:** Gunakan relasi substitute aktif supplier; pastikan pencatatan nomor transaksi dan log audit terjaga; sertakan validasi satuan master.
- **Ask first:** Perubahan struktur tabel inti `purchase_orders` dan `incoming_arrivals` di luar kolom IQC.
- **Never:** Mengizinkan penerimaan (receive) dan pencetakan tag barcode material sebelum ada status `IQC Pass` dari tim QC.

## Out of Scope

- Form inspeksi QC multi-parameter / lembar uji lab yang rumit (cukup ceklis IQC Pass sesuai instruksi operasional).

## Success Criteria

- [ ] Menu `Local PO` dihapus dari launcher dan navigasi sistem.
- [ ] Menu `Purchase Order` dapat digunakan untuk supplier lokal maupun impor.
- [ ] Menu `Kedatangan (Arrival)` menyediakan pemilih manual `[ Lokal ]` vs `[ Impor ]`.
- [ ] Memilih PO pada form Arrival otomatis mengisi daftar item barang dengan benar.
- [ ] Kedatangan lokal memiliki form ringkas (No. Surat Jalan / Invoice, No. PEN, No. AJU, dan item tanpa kontainer/kapal).
- [ ] Menu `Quality -> Incoming Quality` aktif dengan tabel ringkas: Tanggal Arrival, Supp Name, PO No, Item List, dan aksi ceklis `IQC Pass`.
- [ ] Alur Receive material di gudang hanya dapat diproses setelah status `IQC Pass` terpenuhi.
- [ ] Seluruh test suite Pest lulus hijau 100%.
