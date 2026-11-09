# PRD: Tiered Packaging Receive & Arrival-Receive Menu Separation

## Problem Statement

1. **Pencampuran Menu Arrival dan Receive:**
   Saat ini, menu Receive (`/receive`) dan Arrival (`/incoming-arrivals`) saling tumpang tindih. Operator yang membuka menu Receive dan menekan tombol "Terima" justru dilempar ke halaman detail Arrival (`incoming-arrivals.show`). Di dalam detail Arrival sendiri terdapat tabel pencatatan tag receive. Hal ini membingungkan alur kerja: tim logistik dokumen (Arrival) dan tim penerimaan fisik barang (Receive) harus bekerja di halaman yang sama. Seharusnya Arrival murni untuk pencatatan dokumen kedatangan, sedangkan aktivitas penerimaan fisik dan cetak tag berada di menu Receive.

2. **Ketiadaan Hierarki Kemasan Bertingkat (Packaging Hierarchy):**
   Form penerimaan barang saat ini hanya menyediakan input kuantitas mentah satu dimensi. Di lapangan, barang datang secara bertingkat: dari kemasan induk (**Bundle**, misal Pallet), sub-kemasan di dalamnya (**Packing**, misal Box), hingga isi unit per kemasan (**Qty per Packing**, misal EA/PCS). Akibatnya, operator harus menghitung manual di luar sistem dengan kalkulator, yang rentan memicu salah ketik kuantitas.

3. **Inkonsistensi Satuan Berat:**
   Pada beberapa form, satuan berat masih fleksibel atau rancu dengan satuan barang. Standar pabrik mensyaratkan berat bersih (*net weight*) dan berat kotor (*gross weight*) **wajib selalu dalam satuan KGM**.

## Solution

1. **Pemisahan Tegas Domain Menu Arrival vs Receive:**
   - **Menu Arrival (`/incoming-arrivals`):** Murni untuk input dan monitoring kedatangan dokumen logistik (Surat Jalan, No. Kontainer, Segel, Kapal, Invoice, BL, PEN, AJU). Seluruh tombol "Terima" dan tabel tag receive dihilangkan dari halaman Arrival.
   - **Menu Receive (`/receive`):** Menjadi pusat operasional penerimaan fisik barang. Operator melihat daftar kedatangan yang siap diterima (sudah berstatus IQC Pass), memilih item, menginput data kemasan bertingkat, menerbitkan label QR tag, dan melihat riwayat penerimaan barang.

2. **Hierarki Input Penerimaan Bertingkat (Bundle -> Packing Type -> Isi per Packing):**
   - Mengubah form Receive menjadi alur input bertingkat:
     - **Jumlah Bundle:** Jumlah wadah/kemasan besar (contoh: `2`).
     - **Jenis Bundle / Packing Unit:** Tipe kemasan luar (dropdown: `PALLET`, `BUNDLE`, `CRATE`).
     - **Jumlah Packing per Bundle:** Jumlah sub-kemasan di dalam wadah besar (contoh: `5` Box per Pallet). Default `1` bila barang tanpa wadah bertingkat.
     - **Jenis Sub-Packing:** Tipe kemasan dalam (dropdown: `BOX`, `PACK`, `BAG`, `ROLL`).
     - **Qty per Packing:** Kuantitas isi barang dalam satu sub-kemasan (contoh: `2.000`).
     - **Satuan / UOM:** Satuan dasar barang sesuai PO / Master Part (contoh: `EA`, `PCS`, `SHEET`, `KGM`).
     - **Kalkulasi Otomatis Total Qty:**
       $$\text{Total Qty} = \text{Jumlah Bundle} \times \text{Jumlah Packing per Bundle} \times \text{Qty per Packing}$$
       (Contoh: $2 \times 5 \times 2.000 = \mathbf{20.000\text{ EA}}$). Kolom Total Qty tetap dapat disesuaikan manual bila terdapat sisa ganjil (*odd lot*).

3. **Standarisasi Satuan Berat Mutlak KGM:**
   - Field `net_weight` dan `gross_weight` dikunci permanen menggunakan satuan `KGM`. Satuan berat tidak dapat diganti ke unit lain.

4. **Keterangan Lengkap pada Label QR Receive:**
   - Label cetak QR memuat ringkasan struktur kemasan secara jelas:
     `2 PALLET @ 5 BOX @ 2.000 EA (Total: 20.000 EA)`

## User Stories

1. As a staf Gudang Dokumen / Import, I want halaman Arrival hanya menampilkan informasi dokumen pengiriman, so that data kedatangan tidak bercampur dengan proses penerimaan fisik stok.
2. As a staf Gudang Fisik (Receiving), I want mengakses seluruh alur penerimaan langsung dari menu Receive, so that saya tidak perlu membuka halaman modul Arrival.
3. As a operator Receiving, I want menginput jumlah bundle dan isi per packing, so that sistem otomatis menghitung Total Qty yang diterima tanpa perlu kalkulator manual.
4. As a operator Receiving, I want sistem otomatis menetapkan satuan berat ke KGM, so that tidak terjadi kesalahan input satuan berat di gudang.
5. As a operator Receiving, I want label QR yang dicetak mencantumkan rincian bundle dan packing, so that staf lantai produksi dapat langsung mengetahui isi kemasan tanpa membuka kardus.
6. As a supervisor Gudang, I want sistem tetap membatasi total penerimaan agar tidak melebihi sisa PO, so that tidak terjadi penerimaan berlebih (*over-receipt*).

## Objective

Memisahkan tanggung jawab modul Arrival (dokumen kedatangan) dan Receive (penerimaan fisik barang) secara mandiri, serta menerapkan kalkulasi kemasan bertingkat (Bundle $\times$ Packing $\times$ Qty) dengan standarisasi berat mutlak KGM guna mengeliminasi kesalahan hitung manual di gudang.

## Technical Decisions

### 1. Perubahan Skema & Model Database
- Pada tabel `incoming_receives`, tambahkan kolom pendukung sub-kemasan bertingkat:
  - `bundle_count` (integer / numeric): Jumlah kemasan besar (bundle/pallet).
  - `bundle_unit` (string): Jenis kemasan besar (`PALLET`, `BUNDLE`, `CRATE`).
  - `packing_count` (integer / numeric, default 1): Jumlah sub-kemasan per bundle (misal box per pallet).
  - `packing_type` (string): Jenis sub-kemasan (`BOX`, `PACK`, `BAG`, `ROLL`).
  - `packing_qty` (numeric, 20, 4): Isi barang per sub-kemasan.
  - `net_weight` & `gross_weight`: Tetap tersimpan sebagai numeric, dengan unit yang selalu diasumsikan `KGM`.

### 2. Penataan Ulang Halaman & Controller
- **`app/Http/Controllers/IncomingArrivalController.php` & `resources/js/Pages/Incoming/Arrival/Show.vue`:**
  - Hapus tombol aksi `receiveAction` ("Terima") dari tabel item di Arrival Show.
  - Hapus tabel `receiveTag` / riwayat tag receive dari Arrival Show. Halaman Arrival Show murni menampilkan header kedatangan, kontainer, dan daftar item yang didatangkan beserta sisa qty-nya.
- **`app/Http/Controllers/ReceiveController.php` & Halaman Receive:**
  - Perbarui `ReceiveController@index`: Menampilkan daftar kedatangan yang siap di-receive.
  - Aksi "Terima" di `Incoming/Receive/Index.vue` mengarahkan langsung ke halaman operasional Receive khusus:
    - Route `receive.arrival` (`GET /receive/arrivals/{arrival}`): Menampilkan detail item kedatangan khusus untuk tim receiving, beserta form atau modal penerimaan bertingkat per item dan riwayat tag yang sudah diterbitkan untuk kedatangan tersebut.
- **Form Input Receive (`Incoming/Receive/Form.vue`):**
  - Implementasi 3 tingkat input:
    1. Input Bundle (`bundle_count` & `bundle_unit`)
    2. Input Packing (`packing_count` & `packing_type`)
    3. Input Isi (`packing_qty` & `uom`)
    4. Input Berat KGM (`net_weight` & `gross_weight`)
  - Watcher reaktif: Jika `bundle_count`, `packing_count`, atau `packing_qty` berubah, nilai `qty` total otomatis terhitung.
  - Kunci validasi PO limit tetap berlaku terhadap Total Qty.

### 3. Template Cetak Label QR (`resources/views/receives/label.blade.php`)
- Tambahkan baris informasi kemasan:
  `{bundle_count} {bundle_unit} @ {packing_count} {packing_type} @ {packing_qty} {uom}`.

## Project Structure

    app/
      Http/Controllers/
        IncomingArrivalController.php   -> Pembersihan referensi aksi receive
        ReceiveController.php           -> Penambahan alur receive langsung & multi-tier packaging
      Models/
        IncomingReceive.php             -> Penambahan atribut packaging berjenjang
      Support/
        UomCatalog.php                  -> Katalog jenis bundle dan packing type
    resources/
      js/
        Pages/
          Incoming/
            Arrival/Show.vue            -> Murni informasi kedatangan & dokumen
            Receive/Index.vue           -> Navigasi langsung ke penerimaan barang
            Receive/Show.vue            -> Workspace receiving per kedatangan (opsional/terpadu)
            Receive/Form.vue            -> Form kalkulasi bertingkat: Bundle x Packing x Qty
      views/
        receives/
          label.blade.php               -> Cetak label dengan spesifikasi kemasan
    tests/
      Feature/
        TieredReceiveTest.php           -> Uji kalkulasi bertingkat, validasi KGM, dan pemisahan menu

## Commands

    Build: npm run build
    Test:  vendor/bin/pest
    Lint:  vendor/bin/pint --format agent
    Dev:   npm run dev

## Testing Strategy

- **Feature Tests:**
  - Uji kalkulasi otomatis $2 \times 5 \times 2.000 = 20.000$.
  - Uji penolakan satuan berat selain KGM (berat selalu tersimpan dalam KGM).
  - Uji bahwa halaman `incoming-arrivals.show` tidak lagi memuat aksi receive.
  - Uji bahwa alur receive dapat diselesaikan langsung dari modul Receive.
  - Uji batas maksimum PO tetap menahan kelebihan kuantitas.

## Boundaries

- **Always:**
  - Satuan berat `net_weight` dan `gross_weight` selalu `KGM`.
  - Jalankan `vendor/bin/pint --format agent` dan `npm run build` setelah perubahan kode.
  - Seluruh teks label baru menggunakan `useI18n()` / katalog i18n (`id`, `en`, `ko`).
- **Never:**
  - Membiarkan tombol atau alur input receive masuk kembali ke modul Arrival.
  - Menghilangkan validasi batas kuantitas PO yang sudah dibuat sebelumnya.

## Out of Scope

- Merombak modul APK mobile (perubahan ini difokuskan pada web receiving ERP).
- Mengubah alur inspeksi QC / IQC.

## Success Criteria

- [ ] Di halaman Detail Arrival (`Arrival/Show.vue`), tidak ada lagi tombol "Terima" atau tabel tag receive.
- [ ] Tombol "Terima" di menu Receive (`/receive`) tidak lagi melempar user ke halaman Arrival, melainkan masuk ke alur kerja Receive.
- [ ] Form Receive memiliki input berurutan: Bundle $\rightarrow$ Packing Type $\rightarrow$ Isi per Packing $\rightarrow$ Satuan (UOM).
- [ ] Total kuantitas barang otomatis terhitung dari perkalian Bundle $\times$ Packing $\times$ Isi, dan tetap bisa disesuaikan manual.
- [ ] Kolom net weight dan gross weight berlabel dan ber-satuan mutlak KGM.
- [ ] Label QR tag mencantumkan rincian kemasan bertingkat.
- [ ] Seluruh test Pest lulus hijau (`vendor/bin/pest`) dan `npm run build` berjalan tanpa error.

## Open Questions

*Tidak ada open questions yang memblokir. Seluruh batas fungsional telah terdefinisi.*
