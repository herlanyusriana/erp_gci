# PRD: Material Substitute Attributes

## Problem Statement

Pengelola Master Data belum dapat mencatat ukuran, satuan, dan packing type yang
berlaku untuk suatu relasi material substitute dan supplier. Packing type yang
dipakai pada kedatangan sudah dicatat per item Arrival/invoice, tetapi belum
tersedia sebagai pilihan yang konsisten saat mengelola substitute untuk part
yang sama.

## Solution

Lengkapi pengelolaan Material Substitute dengan ukuran opsional berupa teks,
UOM yang mengikuti master part pengganti, dan satu packing type yang wajib
dipilih untuk setiap relasi part, substitute part, dan supplier. Pilihan packing
type bersumber dari packing type yang pernah dicatat pada Arrival untuk part
tersebut. Pengelola juga dapat menambahkan packing type baru dari pengelolaan
substitute; nilai baru disimpan sebagai pilihan untuk part tersebut, tanpa
mencatat transaksi Arrival yang sebenarnya tidak terjadi.

## User Stories

1. As a pengelola Master Data, I want mencatat ukuran pada relasi substitute,
   so that pengguna dapat membedakan spesifikasi substitute yang digunakan
   untuk supplier tertentu.
2. As a pengelola Master Data, I want membiarkan ukuran kosong jika tidak ada,
   so that sistem tidak memaksa nilai ukuran yang tidak diketahui.
3. As a pengelola Master Data, I want melihat UOM dari master part pengganti,
   so that satuan yang ditampilkan konsisten dengan data part dan tidak perlu
   dimasukkan ulang.
4. As a pengelola Master Data, I want memilih satu packing type yang pernah
   tercatat pada Arrival untuk part pengganti, so that pilihan substitute
   mencerminkan kemasan yang benar-benar pernah diterima.
5. As a pengelola Master Data, I want menambahkan packing type baru untuk suatu
   part dari halaman Material Substitute, so that pilihan baru dapat digunakan
   kembali tanpa membuat catatan Arrival palsu.
6. As a pengelola Master Data, I want sistem membantu mengisi packing type
   relasi substitute lama bila hanya ada satu nilai historis yang unik, so that
   migrasi data lama tidak memerlukan input ulang yang tidak perlu.
7. As a pengelola Master Data, I want meninjau dan memilih packing type untuk
   relasi lama yang memiliki beberapa nilai historis, so that sistem tidak
   memilih nilai yang keliru secara otomatis.
8. As a pengguna Material Substitute, I want melihat tanda `-` jika ukuran
   tidak tersedia, so that data yang tidak diketahui dapat dibedakan dari
   ukuran yang tercatat.

## Objective

Membuat data Material Substitute lebih akurat dan dapat dipakai untuk memilih
material pengganti berdasarkan relasi supplier. Keberhasilan diukur dari
kemampuan pengelola menyimpan ukuran opsional, melihat UOM part yang benar, dan
memilih atau menambahkan packing type yang sesuai untuk part tersebut.

## Technical Decisions

- Modul yang diubah adalah pengelolaan Master Data Material Substitute dan
  pencatatan item Arrival yang menjadi sumber historis packing type.
- Ukuran disimpan sebagai teks opsional pada relasi substitute/supplier; nilai
  kosong ditampilkan sebagai `-`.
- UOM bukan atribut yang dimasukkan ulang pada relasi. UI mengambil UOM dari
  master part pengganti.
- Satu relasi part + substitute part + supplier memiliki satu packing type
  pilihan yang wajib diisi saat relasi dibuat atau diperbarui.
- Packing type pada item Arrival tetap menjadi catatan historis per kedatangan
  dan part number. Nilai packing type dari Arrival menjadi pilihan yang tersedia
  untuk part terkait.
- Penambahan packing type dari pengelolaan Material Substitute menyimpan pilihan
  yang dapat digunakan kembali untuk part terkait, tetapi tidak membuat
  transaksi Arrival/invoice.
- Tidak dibuat master packing type global yang terpisah dari part. Pilihan
  packing type dikelola per part dan tersedia dari area Master Data.
- Untuk relasi lama, packing type diisi otomatis bila hanya ada satu nilai
  historis unik untuk part tersebut. Bila ada beberapa nilai historis atau
  belum ada nilai, pengelola memilih/menambahkan nilai saat memperbarui relasi;
  sistem tidak menebak pilihan.
- Validasi server memastikan packing type yang dipilih atau ditambahkan terkait
  dengan part pengganti pada relasi tersebut.
- Tidak ada API eksternal atau kontrak mobile yang berubah.

## Project Structure

    app/Models/                 -> Model Part, PartSubstitute, dan item Arrival
    app/Http/Controllers/       -> Validasi dan alur pengelolaan substitute/Arrival
    database/migrations/        -> Perubahan skema jika diperlukan untuk menyimpan
                                   ukuran dan pilihan packing type per part/relasi
    resources/js/Pages/Master/  -> Halaman Material Substitute
    resources/js/Pages/Incoming/-> Form Arrival yang mencatat packing type
    resources/js/types/         -> Tipe frontend untuk data yang berubah
    tests/Feature/              -> Test perilaku endpoint dan persistence
    docs/prd/                   -> PRD dan dokumen kebutuhan lainnya

## Commands

    Build: npm run build
    Test:  php artisan test
    Lint:  vendor/bin/pint
    Dev:   composer run dev

## Testing Strategy

Test harus memverifikasi perilaku melalui endpoint, data yang tersimpan, dan
props/opsi yang diterima halaman, bukan detail implementasi internal.

- Tambahkan test feature untuk menyimpan ukuran opsional, UOM yang berasal dari
  master part, dan satu packing type yang valid untuk part pengganti.
- Uji penolakan packing type yang tidak terkait dengan part pada relasi.
- Uji bahwa nilai packing type dari item Arrival tersedia sebagai pilihan untuk
  part yang sama, dan tidak bocor menjadi pilihan untuk part lain.
- Uji bahwa menambahkan packing type baru menyimpannya untuk part terkait tanpa
  membuat item Arrival/invoice.
- Uji migrasi/backfill: satu nilai historis unik dapat diisi otomatis; beberapa
  nilai tidak dipilih secara arbitrer dan membutuhkan pilihan pengelola.
- Uji ukuran kosong dan tampilan `-`, serta perubahan/penyimpanan ukuran teks.
- Prior art berada pada test feature Material Substitute, Part Price, Local PO,
  dan Work Order Allocation; gunakan Pest dan transaksi database sesuai pola
  suite repo.

## Boundaries

- **Always:** ikuti pola Laravel/Inertia/Vue yang sudah ada, otorisasi setiap
  action, validasi input di server, gunakan katalog i18n ID/EN/KO untuk teks UI,
  dan jalankan test yang relevan, Pint, serta `npm run build`.
- **Ask first:** perubahan dependensi, perluasan permission/akses, perubahan
  alur bisnis Arrival yang mengubah histori, atau perubahan kontrak API.
- **Never:** membuat catatan Arrival/invoice palsu saat menambah pilihan packing
  type, menebak packing type relasi yang memiliki beberapa nilai historis, atau
  menghapus test yang gagal tanpa persetujuan.

## Out of Scope

- Perubahan BOM maintenance dan penyatuan Purchase Order dengan Local PO; masing-
  masing memiliki PRD tersendiri.
- Perubahan transaksi stok/receive, isi invoice lama, atau aturan kuantitas
  packing pada Arrival.
- Master packing type global yang tidak terikat ke part.
- Menebak atau mengubah catatan Arrival/invoice historis.

## Success Criteria

- [ ] Pengelola dapat menyimpan ukuran bebas atau membiarkannya kosong pada
      relasi substitute/supplier; nilai kosong ditampilkan sebagai `-`.
- [ ] UOM yang ditampilkan berasal dari master part pengganti dan tidak dapat
      berbeda dari UOM master melalui input duplikat pada relasi.
- [ ] Relasi substitute/supplier baru atau yang diperbarui wajib memiliki satu
      packing type yang valid untuk part pengganti.
- [ ] Packing type yang pernah dicatat pada Arrival untuk suatu part tersedia
      sebagai pilihan untuk part yang sama dan tidak untuk part lain.
- [ ] Pengelola dapat menambahkan packing type baru untuk part dari halaman
      Material Substitute; nilai tersedia untuk pemakaian berikutnya tanpa
      menciptakan transaksi Arrival/invoice.
- [ ] Relasi lama dengan satu nilai historis unik diisi otomatis; relasi dengan
      beberapa nilai historis tidak menerima pilihan arbitrer dan dapat
      dilengkapi oleh pengelola.
- [ ] Test feature terkait lulus, `vendor/bin/pint` lulus, dan `npm run build`
      lulus.

## Open Questions

Tidak ada keputusan produk yang belum dijawab dalam wawancara. Detail teknis
implementasi dan migrasi final ditetapkan saat perencanaan berdasarkan skema
aktual serta pola aplikasi.
