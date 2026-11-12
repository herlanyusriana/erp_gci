# PRD: Outgoing FG Delivery (Pengiriman Barang Jadi)

## Problem Statement

Barang jadi yang sudah dilaporkan produksi punya tag dan stok, tetapi tidak ada jalur keluar dari pabrik. Akibatnya stok FG menumpuk tanpa pernah berkurang, kolom Issue pada tab WIP & FG selalu 0, dan tim gudang tidak punya dokumen resmi (surat jalan, invoice, packing list) ketika barang diambil customer. Pengiriman yang terjadi beberapa kali dalam sehari juga tidak tercatat sama sekali.

## Solution

Modul Outgoing FG tempat user membuat pengiriman secara manual: pilih customer, tentukan barang jadi yang dikirim, sistem menyarankan tag FG tertua (FIFO) yang bisa diganti, user mencari barang tersebut di lokasi mesin asalnya lalu memindahkannya ke **Delivery Preparation Area**. Setelah dipindah, **Surat Jalan** bisa dicetak. Invoice dan Packing List ditunda sampai konsepnya final.

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

## Objective

Menyediakan jalur keluar barang jadi yang tercatat, sehingga stok FG berkurang saat barang dipindah ke area pengiriman dan setiap pengiriman punya dokumen resmi. Pengguna utama adalah staf gudang dan supervisor. Keberhasilan berarti: setiap pengiriman menghasilkan nomor DO, memindahkan tag FG dari lokasi mesin ke Delivery Preparation Area, dan Surat Jalan dapat dicetak.

## Technical Decisions

**Modules yang dibangun**

- `customer` — master customer: kode, nama, alamat, kontak, status aktif.
- `location` (perluasan) — tipe lokasi (`storage`, `machine`, `delivery_prep`) dan lokasi Delivery Preparation Area.
- `delivery` — dokumen pengiriman FG beserta baris per tag.
- `stock location` — pencatatan lokasi tag FG pada stok.
- `document` — cetak Surat Jalan (Invoice dan Packing List ditunda).

**Perubahan skema**

- Tabel baru `customers`: `customer_code` (unik), `customer_name`, `address`, `phone`, `email`, `is_active`, timestamps, soft deletes.
- Tabel `locations`: tambah kolom `type` (`storage` | `machine` | `delivery_prep`, default `storage`).
- Tabel baru `deliveries`: `delivery_no` (unik, `DO-YYYYMM-####`), `customer_id`, `delivery_date`, `status` (`draft` | `picked` | `shipped`), `invoice_no` (nullable, disiapkan untuk fase invoice), `truck_no`, `driver_name`, `notes`, `picked_at`, `shipped_at`, `created_by`, `updated_by`, timestamps, soft deletes.
- Tabel baru `delivery_items`: `delivery_id`, `part_stock_id`, `part_id`, `tag`, `qty`, `qty_unit`, `origin_location_code`, `price`, `total_price` (keduanya disiapkan untuk fase invoice), `notes`.
- Tabel `part_stocks`: tambah kolom `location_code` (nullable, mengacu ke `locations.code`).
- Satu lokasi bertipe `machine` dibuat untuk setiap mesin aktif, dengan `code` = kode mesin dan `name` = nama mesin.

**Kontrak perilaku**

- **Lokasi FG** = lokasi mesin yang memproduksinya. Saat Production Result membuat stok FG, `part_stocks.location_code` diisi lokasi mesin tersebut.
- **Picking FIFO**: sistem menyarankan tag FG dengan `received_at` paling lama yang masih punya stok. Saran boleh diganti user dengan tag lain yang valid untuk part tersebut.
- **Pemindahan**: aksi "Pindahkan ke Preparation" mengubah `part_stocks.location_code` dari lokasi mesin ke lokasi bertipe `delivery_prep`, dan status dokumen menjadi `picked`. Stok total tidak berubah; yang berubah hanya lokasi.
- **Issue FG di WIP Inventory** = qty yang sudah dipindah ke Delivery Preparation Area (`deliveries.picked_at`), diatribusikan ke mesin pembuat tag tersebut; nota/supplier pada kartu stok menampilkan nomor DO dan nama customer.
- **Status dokumen**: `draft` (boleh diubah/dihapus, stok belum berpindah) → `picked` (tag sudah di prep) → `shipped` (barang sudah dimuat).
- **Pembatalan**: `draft` boleh dihapus; `picked` boleh dikembalikan ke lokasi asal; `shipped` tidak dapat dibatalkan pada fase ini.
- **Harga**: belum dipakai di fase ini. Kolom `price`/`total_price` disiapkan nullable agar fase invoice tidak perlu migrasi ulang.
- **Validasi**: minimal satu baris; customer wajib; qty tidak boleh melebihi stok tag; tag harus milik part yang dikirim dan masih berstok; satu tag hanya boleh muncul sekali per dokumen.
- **Permission** baru: `delivery.view`, `delivery.create`, `delivery.update`, `delivery.delete`, plus `customer.*` mengikuti pola master lain. Role `warehouse`, `ppic`, `management` mendapat akses.

**Kontrak halaman**

- Outgoing launcher menambah tile **Pengiriman Barang Jadi**.
- Halaman daftar pengiriman: filter tanggal, customer, status; kolom nomor DO, tanggal, customer, jumlah baris, status, aksi.
- Halaman form pengiriman: header (customer, tanggal, truk, driver, catatan) dan tabel baris (part, tag saran FIFO, tag terpilih, qty, satuan, lokasi asal).
- Aksi baris: pindahkan ke preparation (per baris atau semua sekaligus).
- Cetak: **Surat Jalan** saja pada fase ini.

## Project Structure

    app/Models/Customer.php                     -> master customer
    app/Models/Delivery.php                     -> dokumen pengiriman
    app/Models/DeliveryItem.php                 -> baris pengiriman per tag
    app/Http/Controllers/CustomerController.php -> CRUD customer
    app/Http/Controllers/DeliveryController.php -> CRUD + pindah ke preparation
    app/Services/DeliveryService.php            -> picking FIFO, pemindahan lokasi, nomor DO
    resources/js/Pages/Master/Customer/         -> halaman master customer
    resources/js/Pages/Outgoing/Delivery/       -> halaman pengiriman
    resources/views/deliveries/                 -> template surat jalan
    tests/Feature/                              -> test fitur modul ini
    docs/prd/outgoing-fg-delivery.md            -> PRD ini

## Commands

    Build: npm run build
    Test:  php artisan test --compact
    Lint:  vendor/bin/pint --dirty
    Dev:   composer run dev

## Testing Strategy

Test fitur Pest pada level HTTP dan service, mengikuti pola test yang sudah ada (`tests/Feature/WipInventoryTest.php`, `tests/Feature/MaterialIssueWebTest.php`).

- Nomor DO berurutan dan tidak dipakai ulang setelah dokumen dihapus (pola generator yang sudah ada memakai `withTrashed()`).
- Picking FIFO menyarankan tag tertua dan mengecualikan tag tanpa stok.
- Tag saran boleh diganti; tag yang tidak berstok atau bukan milik part ditolak.
- Qty melebihi stok tag ditolak.
- Pemindahan mengubah `part_stocks.location_code` ke lokasi `delivery_prep` dan status menjadi `picked`.
- Draft boleh dihapus tanpa mengubah lokasi; `picked` yang dikembalikan memulihkan lokasi asal.
- Issue FG di WIP Inventory bertambah setelah pemindahan.
- Surat Jalan memuat nomor DO, customer, alamat, dan tag yang benar.
- Otorisasi: user tanpa permission `delivery.*` ditolak.

## Boundaries

- **Always:** otorisasi lewat `Gate::authorize`; validasi qty terhadap stok tag; jalankan `vendor/bin/pint --dirty`, `npm run build`, dan test terkait sebelum selesai; label UI dalam id/en/ko yang seimbang.
- **Ask first:** perubahan skema di luar daftar di atas, menambah dependency, mengubah cara stok FG diposting, atau mengaktifkan pembatalan untuk status `shipped`.
- **Never:** mengurangi `part_stocks.qty` saat pemindahan lokasi (yang berubah hanya lokasi); membuat nomor invoice otomatis tanpa persetujuan finance; mengubah atau menghapus test yang gagal tanpa persetujuan.

## Out of Scope

- Inventory movement antar-lokasi secara umum (hanya pemindahan ke Delivery Preparation Area).
- Gudang FG khusus (lokasi FG sementara = lokasi mesin).
- Sales Order / Delivery Order berbasis pesanan.
- Retur barang dari customer.
- **Invoice dan Packing List** (konsep belum final; kolom harga disiapkan tetapi belum dipakai).
- Master harga jual per customer.
- Perhitungan pajak, diskon, dan integrasi finance.
- Pembatalan pengiriman yang sudah `shipped`.
- Data kemasan pada Packing List (Production Result belum menyimpan bundle/packing).

## Success Criteria

- [ ] Master customer tersedia di Master Data dan dipakai pada dokumen pengiriman.
- [ ] Master lokasi punya tipe (`storage`, `machine`, `delivery_prep`) dan satu lokasi Delivery Preparation Area.
- [ ] Stok FG mencatat lokasi mesin pembuatnya.
- [ ] Pengiriman manual dapat dibuat dengan beberapa baris tag FG dalam satu dokumen.
- [ ] Picking FIFO menyarankan tag tertua dan saran dapat diganti.
- [ ] Pemindahan ke Delivery Preparation Area mengubah lokasi tag dan status dokumen menjadi `picked`, tanpa mengubah total stok.
- [ ] Issue FG pada tab WIP & FG bertambah sebesar qty yang dipindah.
- [ ] Surat Jalan dapat dicetak dan isinya konsisten dengan dokumen.
- [ ] Draft dapat dihapus tanpa efek stok; `picked` dapat dikembalikan ke lokasi asal.
- [ ] `php artisan test --compact` hijau dan `npm run build` sukses.

## Open Questions

- **Pembatalan setelah `shipped`:** apakah boleh dibatalkan dengan mengembalikan tag ke lokasi asal, atau wajib lewat proses retur yang belum ada? Fase ini memilih tidak boleh.
- **Inventory movement menyeluruh:** pemindahan lokasi antar-rak/mesin di luar konteks pengiriman akan dibangun nanti; desain `part_stocks.location_code` sudah disiapkan untuk itu.
- **Gudang FG khusus:** jika nanti ada gudang FG fisik, lokasi mesin dapat dipindahkan massal ke gudang tersebut tanpa mengubah struktur dokumen.
- **Invoice & Packing List:** ditunda. Perlu keputusan format, sumber harga, penomoran invoice, dan isi packing list (Production Result belum menyimpan data kemasan). Kolom `invoice_no`, `price`, dan `total_price` sudah disiapkan agar fase ini tidak perlu migrasi ulang.