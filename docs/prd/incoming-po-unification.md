# PRD: Unified Incoming Supplier PO

## Problem Statement

Pengguna Incoming harus menggunakan menu dan form berbeda untuk mencatat PO
supplier source import dan local. Karena supplier dan kedua jenis catatan PO
tersebar pada alur terpisah, pencatatan serta pencarian PO supplier menjadi
kurang konsisten.

## Solution

Sediakan satu menu, daftar, dan form untuk mencatat PO yang diterima dari
supplier. Pengguna memilih source `local` atau `import`; pilihan supplier hanya
menampilkan supplier dengan kategori source yang sama. Setelah disimpan, source
menentukan alur yang dilanjutkan: PO import diteruskan ke Arrival, sedangkan PO
local diproses melalui Local PO. Kedua lifecycle tetap berbeda meskipun entry
point dan daftar PO disatukan.

## User Stories

1. As a pengguna Incoming, I want mencatat PO yang diterima dari supplier
   melalui satu menu, so that saya tidak perlu memilih halaman terpisah untuk
   transaksi local dan import.
2. As a pengguna Incoming, I want memilih source local atau import saat
   mencatat PO supplier, so that sistem melanjutkan transaksi ke proses yang
   tepat.
3. As a pengguna Incoming, I want daftar supplier difilter berdasarkan source
   PO, so that saya tidak dapat memilih supplier dengan kategori yang tidak
   sesuai.
4. As a administrator Master Supplier, I want menetapkan satu kategori local
   atau import pada setiap supplier, so that supplier dapat difilter secara
   konsisten saat PO dicatat.
5. As a pengguna Incoming, I want field form yang sesuai dengan source terpilih,
   so that data import dan local tetap mengikuti kebutuhan dokumen masing-masing.
6. As a pengguna Incoming, I want mencatat PO import lalu membuat Arrival
   terpisah, so that kedatangan impor dapat mengikuti proses Incoming yang ada.
7. As a pengguna Incoming, I want mencatat PO local melalui Local PO saja,
   so that PO local tidak masuk ke alur Arrival import.
8. As a pengguna Incoming, I want melihat PO import dan Local PO dalam satu
   daftar dengan source yang jelas, so that pencarian dan tindak lanjut tidak
   memerlukan perpindahan menu.
9. As a pengguna Incoming, I want source PO tetap setelah record disimpan,
   so that catatan tidak berpindah ke lifecycle local/import yang salah.
10. As a administrator, I want kategori supplier lama dapat dipulihkan dari
    nama atau riwayat transaksi saat datanya konsisten, so that supplier lama
    dapat digunakan tanpa klasifikasi perkiraan yang tidak dapat diperiksa.
11. As a administrator, I want supplier lama yang tidak dapat diklasifikasikan
    otomatis ditetapkan manual, so that riwayat kosong atau campuran tidak
    menghasilkan source yang keliru.

## Objective

Menyederhanakan pencatatan PO yang diterima dari supplier dengan satu entry
point, sambil mempertahankan alur Import → Arrival dan Local → Local PO. Sukses
berarti pengguna dapat mencatat kedua jenis PO dari satu area, memilih hanya
supplier yang sesuai, dan melihat record keduanya dalam satu daftar.

## Technical Decisions

- Modul yang diubah adalah pencatatan PO incoming, Master Supplier, daftar PO,
  dan titik navigasi Incoming.
- Ini adalah penerimaan/pencatatan dokumen PO dari supplier; aplikasi tidak
  menerbitkan atau mengirim PO kepada supplier.
- Pengguna memilih `local` atau `import` pada form PO. Supplier yang tersedia
  difilter menurut kategori pada Master Supplier; server memvalidasi kecocokan
  supplier dan source.
- Setiap supplier memiliki tepat satu kategori source, `local` atau `import`.
  Kategori supplier baru wajib dipilih melalui Master Supplier dan dapat
  diperbarui sesuai permission yang berlaku.
- Untuk klasifikasi supplier lama, nama supplier yang diawali `PT` menjadi
  kategori awal `local`. Supplier lain mengikuti riwayat transaksi bila semua
  riwayat menunjukkan satu source. Supplier tanpa riwayat atau dengan riwayat
  campuran perlu ditetapkan manual; sistem tidak menebak.
- Source PO tidak dapat diubah setelah penyimpanan. Kesalahan source ditangani
  dengan membatalkan record dan mencatat ulang dengan source yang benar.
- Source `import` mencatat PO supplier pada alur Purchase Order yang ada; proses
  Arrival tetap terpisah dan hanya untuk import.
- Source `local` mencatat PO supplier melalui Local PO yang ada; Local PO tidak
  masuk ke layar/alur Arrival import.
- Field dan validasi khusus setiap source tetap dipertahankan. Penyatuan pada
  tahap ini adalah pada menu, daftar, dan entry point/form, bukan penyamaan
  lifecycle bisnis local dan import.
- Daftar gabungan mencakup record PO import serta Local PO lama dan baru,
  menampilkan source dan mendukung pencarian/filter source.
- Implementasi dapat tetap menggunakan model/record source-specific yang ada;
  tidak ada keputusan untuk menggabungkan seluruh data historis ke satu tabel.
- Permission yang sudah ada untuk Purchase Order, Incoming, Local PO, dan
  Supplier tetap berlaku. Perluasan matriks akses tidak termasuk scope.
- Tidak ada perubahan pada API mobile atau kontrak eksternal.

## Project Structure

    app/Models/                 -> Supplier, PurchaseOrder, IncomingArrival
    app/Http/Controllers/       -> Supplier, Purchase Order, Local PO, Arrival
    app/Policies/               -> Permission Supplier, Purchase Order, Incoming
    database/migrations/        -> Kategori source Supplier dan perubahan skema
                                   minimum jika diperlukan untuk daftar terpadu
    resources/js/Pages/Master/  -> Form Master Supplier
    resources/js/Pages/Incoming/-> Form dan daftar PO gabungan, Arrival, Local PO
    resources/js/types/         -> Tipe Supplier, PO, dan daftar gabungan
    tests/Feature/              -> Test HTTP, filtering source, persistence, alur
    docs/prd/                   -> PRD dan dokumen kebutuhan lainnya

## Commands

    Build: npm run build
    Test:  php artisan test
    Lint:  vendor/bin/pint
    Dev:   composer run dev

## Testing Strategy

Test harus memverifikasi alur dari HTTP, record tersimpan, daftar dan pilihan
supplier yang diterima halaman. Test tidak boleh hanya membuktikan implementasi
internal filter.

- Test Master Supplier untuk create/update kategori `local` dan `import`, serta
  penolakan kategori yang tidak valid atau kosong pada supplier baru.
- Test klasifikasi data lama: supplier dengan nama berawalan `PT` menjadi local;
  supplier lain dengan riwayat source tunggal mengikuti riwayat; riwayat kosong
  atau campuran membutuhkan klasifikasi manual.
- Test form/list PO yang menyatukan record import dan local, menampilkan source,
  dan memfilter hasil sesuai source.
- Test bahwa source `import` hanya menampilkan supplier import dan membuat record
  Purchase Order; Arrival tetap langkah terpisah.
- Test bahwa source `local` hanya menampilkan supplier local dan mengikuti Local
  PO; record tidak diarahkan ke Arrival import.
- Test bahwa server menolak supplier yang kategorinya tidak cocok dengan source
  walaupun request dikirim langsung tanpa UI.
- Test bahwa source tidak dapat diubah setelah PO disimpan.
- Regression test alur Local PO, Arrival/Import PO, supplier-part validation,
  invoice, dan item fields yang sudah ada.
- Prior art: `tests/Feature/LocalPoTest.php`,
  `tests/Feature/PurchaseOrderSupplierPartTest.php`, dan test Incoming terkait;
  gunakan Pest dan transaksi database sesuai pola repo.

## Boundaries

- **Always:** ikuti pola Laravel/Inertia/Vue yang ada, otorisasi setiap action,
  validasi source-supplier di server, gunakan katalog i18n ID/EN/KO untuk teks
  UI, pertahankan field/validasi khusus tiap source, dan jalankan test terkait,
  Pint, serta `npm run build`.
- **Ask first:** mengubah lifecycle Arrival/Local PO, mengubah matriks permission,
  menggabungkan atau menghapus tabel/record historis, perubahan dependency, atau
  perubahan kontrak API.
- **Never:** menerbitkan PO kepada supplier sebagai bagian fitur ini, mengarahkan
  Local PO ke Arrival import, menerima supplier dengan source yang tidak cocok,
  atau menebak klasifikasi supplier lama yang ambigu.

## Out of Scope

- Membuat atau mengirim PO keluar kepada supplier.
- Menyamakan lifecycle import dan local; import tetap memakai Arrival terpisah,
  sedangkan local tetap memakai Local PO.
- Mengubah field transaksi, inventory, invoice, atau aturan receive yang telah
  berlaku pada masing-masing source.
- Mengubah supplier lama yang punya riwayat campuran secara otomatis.
- Approval alur PO atau perubahan hak akses di luar permission yang sudah ada.

## Success Criteria

- [ ] Pengguna dapat membuka satu entry point untuk mencatat PO supplier source
      import maupun local.
- [ ] Source dapat dipilih, tersimpan, dan tidak dapat diubah setelah PO dicatat.
- [ ] Dropdown supplier hanya menampilkan supplier dengan kategori yang cocok;
      server menegakkan aturan yang sama.
- [ ] Supplier baru wajib memiliki satu kategori source `local` atau `import`.
- [ ] Daftar gabungan menampilkan PO import dan Local PO lama maupun baru dengan
      source yang jelas dan filter source berfungsi.
- [ ] PO import melanjutkan ke Arrival terpisah; Local PO tetap berada di
      workflow Local PO dan tidak masuk ke Arrival import.
- [ ] Supplier lama dengan nama diawali `PT` mendapat klasifikasi awal local;
      supplier lain hanya diklasifikasikan otomatis dari riwayat yang konsisten.
- [ ] Supplier lama yang tidak dapat ditentukan secara konsisten memerlukan
      keputusan manual, bukan klasifikasi otomatis yang ditebak.
- [ ] Regression test Local PO dan Purchase Order/Arrival lulus; `vendor/bin/pint`
      dan `npm run build` lulus.

## Open Questions

Tidak ada keputusan produk yang belum dijawab dalam wawancara. Detail teknis
penggabungan tampilan daftar dan penyimpanan kategori supplier ditetapkan saat
perencanaan berdasarkan skema aktual serta pola aplikasi.
