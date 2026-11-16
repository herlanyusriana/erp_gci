# PRD: Outgoing FG Delivery (Pengiriman Barang Jadi)

## Problem Statement

Barang jadi yang sudah dilaporkan produksi punya tag dan stok, tetapi tidak ada jalur keluar dari pabrik. Akibatnya stok FG menumpuk tanpa pernah berkurang, kolom Issue pada tab WIP & FG selalu 0, dan tim gudang tidak punya dokumen resmi (surat jalan, invoice, packing list) ketika barang diambil customer. Pengiriman yang terjadi beberapa kali dalam sehari juga tidak tercatat sama sekali.

## Solution

Modul Outgoing FG dengan **Picking List harian** sebagai pintu masuk: satu tanggal berisi beberapa **seq** (urutan ambil), dan setiap baris menyebut customer, FG, model, part number, qty, serta UOM — tanpa tag FG. Saat picking disimpan, sistem memeriksa stok per baris dan menandainya `OK`, `kurang – in production`, `kurang – no production`, atau `tidak ada di master`. Dari picking list yang sama sistem membuat **satu Delivery per customer** (5 customer = 5 surat jalan, 5 invoice, 5 packing list). Alurnya: **picking list (web)** -> **generate delivery per customer** -> **pindah ke Delivery Preparation Area (APK)**: operator memilih customer/seq dulu, lalu scan label FG + qty + scan troli; **stok FG dipotong di sini** -> **OQC (APK)**: checklist OK/NG per troli -> **cetak Surat Jalan, Invoice, Packing List (web)** -> **rilis data CEISA** -> **isi No. PEN/AJU** -> **submit outgoing**: hanya mengunci dokumen. Nomor PEN/AJU terbit dari CEISA, jadi dokumen dicetak sebelum nomor tersebut ada.

## User Stories

1. Sebagai staf gudang, saya ingin membuat pengiriman manual untuk seorang customer, sehingga pengiriman yang terjadi beberapa kali sehari dapat tercatat.
2. Sebagai staf gudang, saya ingin sistem menyarankan tag FG tertua (FIFO) untuk tiap barang, sehingga saya tidak perlu menebak batch mana yang harus diambil.
3. Sebagai staf gudang, saya ingin mengganti tag yang disarankan dengan tag lain yang benar-benar saya ambil, sehingga catatan sesuai kenyataan di lapangan.
4. Sebagai staf gudang, saya ingin melihat lokasi setiap tag FG (lokasi mesin yang memproduksinya), sehingga saya tahu harus mencari ke mesin mana.
5. Sebagai staf gudang, saya ingin memindahkan tag FG ke Delivery Preparation Area, sehingga barang siap dimuat dan stok keluar dari lokasi asalnya.
6. Sebagai staf gudang, saya ingin mencetak Surat Jalan, sehingga pengemudi punya dokumen pengantar.
7. Sebagai supervisor, saya ingin melihat daftar pengiriman per periode dan statusnya, sehingga saya bisa memantau barang yang sudah keluar.
8. Sebagai supervisor, saya ingin menandai pengiriman sebagai terkirim, sehingga laporan pengiriman akurat.
9. Sebagai admin, saya ingin mengelola master customer, sehingga nama dan alamat pada dokumen konsisten.
10. Sebagai admin, saya ingin mengelola master lokasi termasuk tipe lokasi dan Delivery Preparation Area, sehingga pemilihan lokasi asal dan tujuan pengiriman benar.
11. Sebagai staf gudang, saya ingin pengiriman yang masih draft bisa saya ubah atau hapus, sehingga kesalahan input tidak mengunci stok.
12. Sebagai staf gudang, saya ingin sistem menolak pengiriman tanpa baris barang atau tanpa customer, sehingga tidak ada dokumen kosong.
13. Sebagai staf gudang, saya ingin sistem menolak qty yang melebihi stok tag, sehingga stok tidak menjadi negatif.
14. Sebagai staf gudang, saya ingin menyusun picking list harian berisi beberapa seq dan beberapa customer dalam satu dokumen, sehingga rencana pengambilan barang sesuai urutan keberangkatan.
15. Sebagai staf gudang, saya ingin setiap baris picking langsung menunjukkan status stok (OK / kurang – in production / kurang – no production / tidak ada di master), sehingga saya tahu baris mana yang harus menunggu produksi.
16. Sebagai staf gudang, saya ingin satu picking list otomatis menghasilkan satu dokumen pengiriman per customer, sehingga tiap customer punya surat jalan, invoice, dan packing list sendiri.
17. Sebagai staf gudang, saya ingin stok berkurang saat barang benar-benar dipindah ke area pengiriman, sehingga catatan stok mengikuti perpindahan fisik.

## Objective

Menyediakan jalur keluar barang jadi yang tercatat, sehingga stok FG berkurang saat barang dipindah ke area pengiriman dan setiap pengiriman punya dokumen resmi. Pengguna utama adalah staf gudang dan supervisor. Keberhasilan berarti: setiap pengiriman menghasilkan nomor DO, memindahkan tag FG dari lokasi mesin ke Delivery Preparation Area, dan Surat Jalan dapat dicetak.

## Technical Decisions

**Modules yang dibangun**

- `customer` — master customer: kode, nama, alamat, kontak, status aktif.
- `location` (perluasan) — tipe lokasi (`storage`, `machine`, `delivery_prep`) dan lokasi Delivery Preparation Area.
- `picking list` — rencana picking harian: tanggal, seq, customer, FG, qty, status stok.
- `customer price` — master harga jual barang jadi per customer (sumber harga invoice).
- `delivery` — dokumen pengiriman FG per customer; baris berupa rencana (part + qty), tag dicatat saat dipindai.
- `stock location` — pencatatan lokasi tag FG pada stok.
- `document` — cetak label pengiriman, Surat Jalan, Invoice, dan Packing List.

**Perubahan skema**

- Tabel baru `customers`: `customer_code` (unik), `customer_name`, `address`, `phone`, `email`, `is_active`, timestamps, soft deletes.
- Tabel `locations`: tambah kolom `type` (`storage` | `machine` | `delivery_prep`, default `storage`).
- Tabel baru `deliveries`: `delivery_no` (unik, `DO-YYYYMM-####`), `customer_id`, `delivery_date`, `status` (`draft` | `picked` | `qc_passed` | `loaded` | `released`), `invoice_no` (diisi manual), `qc_status` (`pending` | `passed` | `rejected`), `qc_notes`, `qc_checked_at`, `qc_checked_by`, `truck_no`, `driver_name`, `notes`, `picked_at`, `loaded_at`, `released_at`, `created_by`, `updated_by`, timestamps, soft deletes.
- Tabel baru `customer_prices`: `customer_id`, `part_id`, `price`, `currency` (default `IDR`), `is_active`, `created_by`, `updated_by`, timestamps; unik per (`customer_id`, `part_id`).
- Tabel baru `picking_lists`: `picking_no` (unik, `PK-YYYYMM-####`), `picking_date`, `status` (`draft` | `picked`), `notes`, `created_by`, `updated_by`, timestamps, soft deletes.
- Tabel baru `picking_list_items`: `picking_list_id`, `sequence`, `customer_id`, `part_id`, `qty`, `qty_unit`, `stock_available`, `stock_shortage`, `stock_status` (`ok` | `short_in_production` | `short_no_production` | `no_data`), `notes`.
- Tabel baru `delivery_items`: `delivery_id`, `part_stock_id` (nullable sejak baris menjadi rencana), `part_id`, `tag` (nullable), `qty`, `qty_unit`, `origin_location_code`, `price`, `total_price`, `sequence`, `is_picked`, `notes`.
- `deliveries`: `picking_list_id` (nullable, asal picking), `stock_deducted_at` (penanda stok sudah dipotong saat pindah). `delivery_scans`: `origin_location_code` (lokasi asal tag untuk pengembalian).
- Tabel baru `delivery_scans`: `delivery_id`, `scan_type` (`trolley` | `tag`), `code`, `part_stock_id` (nullable), `qty` (nullable, qty yang dipindah saat scan FG), `qc_status` (`ok`/`ng`, OQC per troli), `qc_notes`, `qc_checked_at`, `qc_checked_by`, `scanned_by`, `scanned_at`; unik per (`delivery_id`, `scan_type`, `code`).
- `deliveries`: `pen_no`, `aju_no` (diisi setelah CEISA dirilis), `ceisa_released_at`, `ceisa_released_by`. `delivery_items`: `sequence` (urutan keberangkatan) dan `is_picked` (centang FG sudah diambil).
- Tabel baru `trolleys` (mirror): `external_id`, `code` (unik), `type`, `kind`, `capacity`, `location`, `notes`, `synced_at`. Tanpa `status` (pergerakan, bukan master).
- Tabel `part_stocks`: tambah kolom `location_code` (nullable, mengacu ke `locations.code`).
- Satu lokasi bertipe `machine` dibuat untuk setiap mesin aktif, dengan `code` = kode mesin dan `name` = nama mesin.

**Kontrak perilaku**

- **Lokasi FG** = lokasi mesin yang memproduksinya. Saat Production Result membuat stok FG, `part_stocks.location_code` diisi lokasi mesin tersebut.
- **Picking FIFO**: sistem menyarankan tag FG dengan `received_at` paling lama yang masih punya stok. Saran boleh diganti user dengan tag lain yang valid untuk part tersebut.
- **Picking list**: satu tanggal, banyak `sequence`, banyak customer; tiap baris wajib FG aktif. Di form web, customer dipilih **sekali per seq** dan diwariskan ke seluruh baris seq tersebut (server tetap menyimpan `customer_id` per baris). Baris kosong (tanpa FG dan tanpa qty) dibuang sebelum submit, dan pesan validasi dipetakan ke `items.<uid>.<field>` agar menempel pada baris yang benar. Cek stok saat simpan: `ok`, `short_in_production` (masih ada WO `planned`/`in_progress` untuk part itu), `short_no_production`, atau `no_data` (part belum punya baris stok). Alokasi dalam satu picking list ikut mengurangi stok tersedia baris berikutnya.
- **Generate delivery**: satu customer = satu `deliveries` draft; baris picking menjadi `delivery_items` (part + qty + sequence, tanpa tag). Harga jual diambil dari `customer_prices` customer tersebut (hanya yang `is_active`) dan disalin sebagai snapshot (`price`, `total_price`), sehingga perubahan harga tidak mengubah dokumen lama. Picking list berstatus `picked` setelah generate dan tidak dapat diubah lagi.
- **Master harga jual** (`/customer-prices`, permission `customer_price.*`): satu halaman = satu customer × seluruh FG aktif; simpan sekaligus, harga kosong berarti harga part itu dihapus. Pengiriman yang barisnya belum punya harga menampilkan peringatan di halaman detail dan invoice tercetak 0.
- **Pemindahan**: operator memilih customer/seq di APK lalu memindai label FG (+ qty) dan troli. Setelah seluruh rencana qty tertutup tag dan minimal satu troli dipindai, dokumen menjadi `picked`, tag pindah ke lokasi `delivery_prep`, dan **`part_stocks.qty` dipotong sekali** sebesar qty tag yang dipindai (`deliveries.stock_deducted_at`). QC menolak tidak mengembalikan stok otomatis; pemindahan ulang tidak memotong dua kali. Aksi "kembalikan ke asal" memulihkan qty dan lokasi.
- **Issue FG di WIP Inventory** = qty yang sudah dipindah ke Delivery Preparation Area (`deliveries.picked_at`), diatribusikan ke mesin pembuat tag tersebut; nota/supplier pada kartu stok menampilkan nomor DO dan nama customer.
- **Status dokumen**: `draft` (boleh diubah/dihapus, stok belum berpindah) → `picked` (barang di prep, stok sudah dipotong) → `qc_passed` → `released` (outgoing disubmit, dokumen terkunci).
- **Pembatalan**: `draft` boleh dihapus; `picked` boleh dikembalikan ke lokasi asal; `shipped` tidak dapat dibatalkan pada fase ini.
- **Harga**: input manual per baris; `total_price` = `qty × price`. Dipakai pada Invoice.
- **QC**: hanya bisa dilakukan saat status `picked`. Lolos → `qc_passed`; ditolak → kembali `draft` dengan alasan wajib diisi.
- **Troli**: master dan label QR diterbitkan oleh aplikasi terpisah **Trolley Scan** (Laravel, DB sendiri). ERP tidak membuat atau mencetak troli.
- **Mirror master troli**: snapshot JSON disimpan di `database/seeders/data/trolleys.json` lalu di-seed ke tabel `trolleys`. Tidak ada panggilan API saat runtime.
- **Loading (tahap 4)**: operator memindai label troli (QR dari aplikasi Trolley Scan) lalu setiap label FG. Label FG dikenali dari QR produksi (`type: fg`, `result_id`) atau tag stok FG. Scan disimpan di `delivery_scans`; satu kode dihitung sekali per dokumen.
- **Validasi troli**: kode harus terdaftar di master. Status `in/out` **tidak** disimpan di master karena itu pergerakan (checkout/checkin) yang berubah terus di aplikasi Trolley Scan; menyimpannya di ERP hanya membuat data basi dan memblokir pemindaian yang sah.
- **Sumber master**: snapshot JSON `database/seeders/data/trolleys.json` (dari Export CSV aplikasi trolley) di-seed lewat `php artisan db:seed --class=TrolleySeeder`. Untuk pembaruan ad-hoc: `php artisan trolleys:import <csv>`. Keduanya idempoten.
- **Pilihan troli di web**: daftar troli `in` diurutkan sesuai jenis (`kind`: reinforce/backplate/compbase) yang cocok dengan model FG pengiriman.
- **Rilis label**: hanya dari `qc_passed` **dan** setelah minimal satu troli serta seluruh tag FG dipindai; menandai `loaded_at`.
- **Scan ditolak** bila tag bukan milik pengiriman ini, bila belum QC lolos, atau bila dokumen sudah dirilis.
- **APK**: layar loading membaca `GET /api/deliveries` + `loading-context`, memindai lewat `POST /api/deliveries/{id}/scan`. Web menyediakan input scan manual sebagai cadangan.
- **Rilis dokumen**: hanya dari `loaded` dan wajib `invoice_no`; mencetak Surat Jalan, Invoice, Packing List hanya boleh setelah `released`.
- **Tanpa PEN/AJU**: dokumen pengiriman tidak memuat nomor kepabeanan.
- **Validasi**: minimal satu baris; customer wajib; qty tidak boleh melebihi sisa rencana part pada dokumen maupun stok tag; tag harus milik part yang direncanakan dan masih berstok; satu tag hanya boleh muncul sekali per dokumen.
- **Permission** baru: `delivery.view`, `delivery.create`, `delivery.update`, `delivery.delete`, plus `customer.*` mengikuti pola master lain. Role `warehouse`, `ppic`, `management` mendapat akses.

**Kontrak halaman**

- Outgoing launcher menambah tile **Pengiriman Barang Jadi**.
- Halaman daftar pengiriman: filter tanggal, customer, status; kolom nomor DO, tanggal, customer, jumlah baris, status, aksi.
- Halaman form picking list: header (tanggal, catatan) + kartu per **seq** berisi customer seq, tombol duplikat/hapus seq, dan tabel baris (No, FG, Qty, UOM). Pilihan FG menampilkan part number + nama/model; stok hanya sebagai saran di daftar pilihan, status final dihitung server saat simpan. Hapus baris/seq memakai undo, bukan dialog konfirmasi; tombol "Buat Pengiriman" memakai modal yang menyebut customer dan akibatnya (picking terkunci).
- Aksi baris: pindahkan ke preparation (per baris atau semua sekaligus).
- Halaman detail menampilkan lima tahap beserta panel aksi sesuai status: pindah ke preparation, QC lolos/tolak, rilis label, rilis dokumen.
- Cetak: label pengiriman (per tag), Surat Jalan, Invoice, Packing List.

## Project Structure

    app/Models/CustomerPrice.php                -> harga jual FG per customer
    app/Http/Controllers/CustomerPriceController.php -> master harga jual (bulk per customer)
    resources/js/Pages/Master/CustomerPrice/    -> halaman harga jual
    app/Models/PickingList.php                  -> rencana picking harian
    app/Models/PickingListItem.php              -> baris picking (customer + FG + qty)
    app/Http/Controllers/PickingListController.php -> CRUD picking + generate delivery
    app/Services/PickingListService.php         -> cek stok picking + generate delivery per customer
    resources/js/Pages/Outgoing/Picking/        -> halaman picking list
    app/Models/Customer.php                     -> master customer
    app/Models/Delivery.php                     -> dokumen pengiriman
    app/Models/DeliveryItem.php                 -> baris pengiriman per tag
    app/Http/Controllers/CustomerController.php -> CRUD customer
    app/Http/Controllers/DeliveryController.php -> CRUD + pindah ke preparation
    app/Services/DeliveryService.php            -> picking FIFO, pemindahan lokasi, nomor DO
    resources/js/Pages/Master/Customer/         -> halaman master customer
    resources/js/Pages/Outgoing/Delivery/       -> halaman pengiriman
    resources/views/deliveries/                 -> label, label troli, surat jalan, invoice, packing list
    tests/Feature/                              -> test fitur modul ini
    docs/prd/outgoing-fg-delivery.md            -> PRD ini

## Commands

    Build: npm run build
    Test:  php artisan test --compact
    Lint:  vendor/bin/pint --dirty
    Dev:   composer run dev

## Testing Strategy

Test fitur Pest pada level HTTP dan service, mengikuti pola test yang sudah ada (`tests/Feature/WipInventoryTest.php`, `tests/Feature/MaterialIssueWebTest.php`).

- Nomor picking (`PK-YYYYMM-####`) dan DO berurutan, tidak dipakai ulang setelah dokumen dihapus (`withTrashed()`).
- Picking menandai `ok`, `short_no_production`, `short_in_production` (WO aktif), dan `no_data` dengan benar, termasuk alokasi antar baris.
- Generate delivery membuat satu dokumen per customer dengan baris rencana tanpa tag dan belum memotong stok.
- Pemindahan memotong stok sekali; QC tolak + pindah ulang tidak memotong dua kali; "kembalikan ke asal" memulihkan qty dan lokasi; submit tidak mengubah stok lagi.
- Picking FIFO menyarankan tag tertua dan mengecualikan tag tanpa stok.
- Tag saran boleh diganti; tag yang tidak berstok atau bukan milik part ditolak.
- Qty melebihi stok tag ditolak.
- Pemindahan mengubah `part_stocks.location_code` ke lokasi `delivery_prep` dan status menjadi `picked`.
- Draft boleh dihapus tanpa mengubah lokasi; `picked` yang dikembalikan memulihkan lokasi asal.
- Issue FG di WIP Inventory bertambah setelah pemindahan.
- Surat Jalan, Invoice, dan Packing List memuat nomor DO, customer, dan tag yang benar.
- Setiap tahap menolak aksi yang belum waktunya (QC sebelum picked, label sebelum QC, dokumen sebelum loaded, cetak sebelum released).
- QC ditolak mengembalikan dokumen ke draft dan menyimpan alasan.
- Otorisasi: user tanpa permission `delivery.*` ditolak.

## Boundaries

- **Always:** otorisasi lewat `Gate::authorize`; validasi qty terhadap stok tag; jalankan `vendor/bin/pint --dirty`, `npm run build`, dan test terkait sebelum selesai; label UI dalam id/en/ko yang seimbang.
- **Ask first:** perubahan skema di luar daftar di atas, menambah dependency, mengubah cara stok FG diposting, atau mengaktifkan pembatalan untuk status `shipped`.
- **Never:** memotong `part_stocks.qty` lebih dari sekali untuk satu pemindahan; memotong stok lagi saat submit outgoing; membuat nomor invoice otomatis tanpa persetujuan finance; mengubah atau menghapus test yang gagal tanpa persetujuan.

## Out of Scope

- Inventory movement antar-lokasi secara umum (hanya pemindahan ke Delivery Preparation Area).
- Gudang FG khusus (lokasi FG sementara = lokasi mesin).
- Sales Order / Delivery Order berbasis pesanan.
- Retur barang dari customer.
- Perhitungan pajak, diskon, dan termin pembayaran pada invoice.
- Perhitungan pajak, diskon, dan integrasi finance.
- Nomor PEN/AJU pada dokumen pengiriman (memang tidak dipakai).
- Pembatalan pengiriman yang sudah `shipped`.
- Data kemasan pada Packing List (Production Result belum menyimpan bundle/packing).

## Success Criteria

- [ ] Master customer tersedia di Master Data dan dipakai pada dokumen pengiriman.
- [ ] Master lokasi punya tipe (`storage`, `machine`, `delivery_prep`) dan satu lokasi Delivery Preparation Area.
- [ ] Stok FG mencatat lokasi mesin pembuatnya.
- [ ] Pengiriman tidak lagi dibuat manual: satu-satunya pintu masuk adalah picking list (satu customer = satu dokumen).
- [ ] Picking FIFO menyarankan tag tertua dan saran dapat diganti.
- [ ] Picking list harian dapat dibuat dengan beberapa seq dan beberapa customer, lengkap dengan status stok per baris.
- [ ] Satu picking list menghasilkan satu pengiriman (draft) per customer.
- [ ] Master harga jual per customer tersedia dan harga tersalin ke baris pengiriman saat generate.
- [ ] Pemindahan ke Delivery Preparation Area mengubah lokasi tag, memotong stok FG sekali, dan status dokumen menjadi `picked`.
- [ ] Issue FG pada tab WIP & FG bertambah sebesar qty yang dipindah.
- [ ] Picking menampilkan urutan keberangkatan (seq) dan centang "FG sudah diambil" per baris.
- [ ] Pemindahan dilakukan di APK dengan urutan scan label FG -> qty -> scan troli.
- [ ] OQC per troli (OK/NG) wajib lengkap dan semua OK sebelum lolos.
- [ ] Surat Jalan, Invoice, dan Packing List dapat dicetak setelah OQC lolos.
- [ ] Rilis data CEISA tersimpan sebagai gate sebelum No. PEN/AJU diisi.
- [ ] No. PEN/AJU tidak dapat diisi sebelum data CEISA dirilis.
- [ ] Submit outgoing wajib CEISA dirilis + No. PEN/AJU, dan tidak mengubah stok lagi (stok sudah dipotong saat pemindahan).
- [ ] Dokumen tetap dapat dicetak ulang setelah outgoing disubmit.
- [ ] Loading hanya bisa dirilis setelah minimal satu label troli dan seluruh label FG dipindai.
- [ ] Master troli ter-seed dari snapshot (202 troli) dan hasil pindai tervalidasi terhadap kode yang terdaftar.
- [ ] APK dapat memindai troli dan label FG lewat endpoint delivery.
- [ ] QC dapat menolak dan mengembalikan dokumen ke tahap DO dengan alasan tersimpan.
- [ ] Label pengiriman dapat dicetak setelah picking.
- [ ] Surat Jalan, Invoice, dan Packing List hanya dapat dicetak setelah dokumen dirilis, dan isinya konsisten.
- [ ] Draft dapat dihapus tanpa efek stok; pengiriman yang stoknya sudah dipotong dapat dikembalikan ke lokasi asal dan stoknya pulih.
- [ ] `php artisan test --compact` hijau dan `npm run build` sukses.

## Open Questions

- **Pembatalan setelah `shipped`:** apakah boleh dibatalkan dengan mengembalikan tag ke lokasi asal, atau wajib lewat proses retur yang belum ada? Fase ini memilih tidak boleh.
- **Inventory movement menyeluruh:** pemindahan lokasi antar-rak/mesin di luar konteks pengiriman akan dibangun nanti; desain `part_stocks.location_code` sudah disiapkan untuk itu.
- **Gudang FG khusus:** jika nanti ada gudang FG fisik, lokasi mesin dapat dipindahkan massal ke gudang tersebut tanpa mengubah struktur dokumen.
- **Penomoran invoice:** diisi manual; bila finance punya aturan otomatis, sistem dapat diubah.
- **Data kemasan pada Packing List:** Production Result belum menyimpan bundle/packing, sehingga packing list menampilkan tag, qty, satuan, dan lokasi asal.