# PRD: BOM Maintenance

## Problem Statement

Pengguna ERP belum dapat menambahkan BOM melalui aplikasi. Halaman yang ada
hanya menampilkan daftar dan detail, sehingga BOM baru tidak dapat disiapkan
dengan data yang dibutuhkan untuk produksi tanpa proses di luar alur aplikasi.

## Solution

Tambahkan alur untuk membuat BOM lengkap bagi part FG yang sudah terdaftar di
Master Part, serta mengedit BOM yang sudah ada. BOM mencakup langkah proses,
komponen, dan data pendukung seperti mesin, part hasil antara, jumlah, dan UOM.
Nomor BOM dibuat otomatis. BOM baru langsung aktif setelah berhasil disimpan.
Untuk tahap ini, perubahan pada BOM yang ada memperbarui BOM yang sama; alur
approval direncanakan untuk pengembangan berikutnya, bukan bagian fitur ini.

## User Stories

1. As a pengguna dengan akses BOM, I want membuat BOM untuk part FG yang sudah
   terdaftar, so that BOM dapat disiapkan melalui aplikasi ERP.
2. As a pengguna dengan akses BOM, I want menambahkan seluruh langkah dan
   komponen BOM dalam satu alur, so that BOM yang tersimpan dapat dipakai dalam
   proses produksi.
3. As a pengguna dengan akses BOM, I want memilih part dari Master Part,
   so that BOM tidak mengacu pada part yang belum terdaftar.
4. As a pengguna dengan akses BOM, I want nomor BOM dibuat otomatis,
   so that nomor BOM tidak perlu diketik dan tidak bentrok dengan nomor lain.
5. As a pengguna dengan akses BOM, I want mengedit BOM yang sudah ada,
   so that koreksi atau perubahan BOM dapat dilakukan melalui aplikasi.
6. As a pengguna dengan akses BOM, I want menyimpan langkah dengan urutan,
   proses/mesin, komponen, dan part hasil antara bila relevan,
   so that BOM merepresentasikan routing produksi yang digunakan.
7. As a pengguna produksi, I want BOM baru yang valid langsung aktif,
   so that BOM dapat dipakai untuk Work Order tanpa langkah aktivasi manual.
8. As a administrator, I want akses create dan update BOM tetap dibatasi oleh
   permission BOM, so that hanya role yang berwenang dapat mengubah struktur
   produksi.

## Objective

Menyediakan jalur web ERP untuk menambah dan mengedit BOM routing lengkap bagi
produk FG. Keberhasilan berarti pengguna berizin dapat menyimpan BOM beserta
langkah produksinya dan BOM baru yang valid langsung tersedia untuk proses
Work Order.

## Technical Decisions

- Modul yang diubah adalah pengelolaan Master BOM pada aplikasi web ERP.
- BOM utama dibuat untuk part FG. RM dan WIP dapat menjadi komponen; WIP juga
  dapat menjadi part hasil antara dalam langkah produksi.
- Part harus sudah dibuat lewat Master Part sebelum BOM dibuat. Pembuatan part
  tidak dilakukan di dalam form BOM.
- BOM lengkap mendukung urutan proses, proses, mesin, parent/output part,
  komponen child, jumlah, UOM, ukuran, special code, dan source.
- Nomor BOM dibuat otomatis dan unik oleh aplikasi. Format persis mengikuti
  keputusan implementasi berdasarkan pola dan skema yang ada.
- Membuat BOM untuk part FG yang belum memiliki BOM menghasilkan record baru
  yang langsung aktif setelah validasi berhasil.
- Mengedit BOM yang ada memperbarui record BOM yang sama; tidak membuat versi
  baru dalam lingkup ini.
- Hak akses menggunakan permission BOM yang sudah ada. Role PPIC dan Engineering
  saat ini memiliki permission create/update BOM; perubahan matriks permission
  bukan bagian scope.
- Approval workflow untuk perubahan BOM tidak termasuk tahap ini dan dicatat
  sebagai kebutuhan pengembangan berikutnya.
- Tidak ada kontrak API eksternal atau API mobile yang berubah.

## Project Structure

    app/Http/Controllers/       -> Aksi daftar, detail, create, store, dan update BOM
    app/Models/                 -> Model Bom dan BomItem
    app/Policies/               -> Otorisasi BOM
    database/migrations/        -> Perubahan skema jika diperlukan
    resources/js/Pages/Master/  -> Halaman daftar, detail, tambah, dan edit BOM
    resources/js/types/         -> Tipe TypeScript BOM dan item BOM
    tests/Feature/              -> Test HTTP dan persistence BOM
    docs/prd/                   -> PRD dan dokumen kebutuhan lainnya

## Commands

    Build: npm run build
    Test:  php artisan test
    Lint:  vendor/bin/pint
    Dev:   composer run dev

## Testing Strategy

Test harus membuktikan perilaku dari sisi HTTP dan data tersimpan, bukan detail
implementasi controller atau komponen.

- Test bahwa pengguna berizin dapat membuat BOM FG lengkap dengan item dan nomor
  BOM otomatis yang unik.
- Test bahwa part yang belum terdaftar atau bukan FG tidak dapat menjadi BOM
  utama.
- Test bahwa pengguna tanpa permission create/update ditolak.
- Test bahwa item proses mempertahankan sequence, process/machine, parent/output,
  child component, jumlah, UOM, dan metadata opsional yang dikirim.
- Test bahwa BOM baru valid aktif dan dapat dipakai oleh alur Work Order yang
  menggunakan active BOM.
- Test bahwa edit memperbarui BOM beserta item-itemnya pada record yang sama dan
  tidak membuat versi BOM baru.
- Field wajib per item belum ditetapkan; test validasi final menunggu keputusan
  tersebut dan dicatat pada Open Questions.
- Prior art: `tests/Feature/PartTypeGuardTest.php` untuk aturan part dan
  `tests/Feature/ProductionPlanTest.php` untuk alur produksi; gunakan Pest dan
  transaksi database sesuai pola suite repo.

## Boundaries

- **Always:** ikuti pola Laravel/Inertia/Vue yang sudah ada, lakukan otorisasi
  setiap action, validasi data di server, gunakan katalog i18n ID/EN/KO untuk
  teks UI, dan jalankan test terkait, Pint, serta `npm run build`.
- **Ask first:** perubahan dependency, perubahan matriks permission, perubahan
  alur Work Order yang sudah ada, dan penambahan approval workflow.
- **Never:** membuat BOM untuk part yang belum terdaftar, membiarkan BOM baru
  yang tidak lengkap dipakai produksi, menebak aturan field wajib yang masih
  terbuka, atau menghapus test yang gagal tanpa persetujuan.

## Out of Scope

- Membuat atau mengedit part di dalam form BOM; part dibuat melalui Master Part.
- BOM utama untuk tipe part selain FG.
- Pembuatan versi baru BOM saat mengedit BOM yang ada.
- Approval dan workflow peninjauan BOM pada tahap ini.
- Mengubah aturan kalkulasi material, konsumsi stok, atau alur Work Order yang
  bergantung pada BOM.

## Success Criteria

- [ ] Pengguna berpermission BOM dapat membuat BOM lengkap untuk part FG yang
      sudah ada di Master Part.
- [ ] Pengguna dapat menambah dan mengatur item BOM dengan data routing yang
      disepakati serta metadata terkait.
- [ ] Nomor BOM dibuat otomatis dan unik.
- [ ] BOM baru yang valid langsung aktif dan dapat digunakan untuk Work Order.
- [ ] Pengguna berpermission update dapat mengedit BOM yang ada tanpa membuat
      record BOM versi baru.
- [ ] Pengguna tanpa permission yang sesuai tidak dapat membuat atau mengubah
      BOM.
- [ ] Test feature terkait lulus, `vendor/bin/pint` lulus, dan `npm run build`
      lulus.

## Open Questions

- Field apa yang wajib diisi untuk setiap item BOM? Rekomendasi validasi awal
  (komponen, jumlah, UOM wajib; urutan wajib untuk item proses; proses/mesin dan
  parent WIP wajib hanya jika item mewakili langkah produksi) belum diputuskan.
