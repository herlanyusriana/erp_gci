# PRD: Location Scan (Material Tracker)

Module id: `location-scan`
Inisiatif: `docs/prd/material-tracker-clarity.md`

## Problem Statement

Sistem tidak tahu material disimpan di rak mana. Tidak ada master lokasi, tidak
ada label lokasi, dan kolom `location_code` yang sudah ada di dokumen penerimaan
**tidak pernah diisi maupun dibaca** di mana pun. Akibatnya ketika operator gudang
mengambil material, tidak ada cara memastikan barang itu diambil dari tempat yang
benar, dan tidak ada jejak rak asal material yang keluar.

Bagi planner dan supervisor, ini berarti tidak ada cara menelusuri kembali dari
mana sebuah material diambil ketika ada keluhan kualitas atau kekurangan stok.
Bagi operator, tidak ada pembanding ketika rak material dipindah tanpa
pemberitahuan, sehingga kesalahan ambil baru ketahuan setelah sampai di lini
produksi.

## Solution

ERP memiliki **master lokasi rak** yang bisa diisi admin, masing-masing dengan
**label QR** yang bisa dicetak dan ditempel di rak. Saat penerimaan barang,
lokasi rak diisi sehingga setiap tag material tahu tempatnya.

Di APK, setelah operator memindai material dan materialnya cocok dengan kebutuhan
WO, operator **memindai QR lokasi rak**. Lokasi yang dipindai **dicatat** pada
dokumen pengeluaran. Bila lokasi berbeda dari data penerimaan, sistem memberi
**peringatan** — tetapi **tidak menghalangi** pengeluaran.

Untuk stok yang terlanjur tidak punya lokasi, tersedia layar **Setup Lokasi** di
web untuk menetapkannya.

## User Stories

1. Sebagai admin gudang, saya ingin mendaftarkan lokasi rak, supaya setiap tempat
   penyimpanan punya identitas resmi.
2. Sebagai admin gudang, saya ingin mencetak label QR per lokasi, supaya rak bisa
   ditandai dan dipindai.
3. Sebagai operator penerimaan, saya ingin mengisi lokasi rak saat menerima
   barang, supaya material baru langsung punya jejak tempat.
4. Sebagai operator gudang, saya ingin memindai QR lokasi rak setelah material
   cocok, supaya rak asal material tercatat tanpa mengetik.
5. Sebagai operator gudang, saya ingin diberi peringatan bila rak yang saya pindai
   berbeda dari data penerimaan, supaya saya sadar ada ketidaksesuaian.
6. Sebagai operator gudang, saya ingin tetap bisa melanjutkan pengeluaran walau
   lokasi berbeda, supaya satu data rak yang basi tidak menghentikan produksi.
7. Sebagai operator gudang, saya ingin lokasi yang dipindai tercatat di dokumen
   pengeluaran, supaya ada jejak yang bisa ditelusuri.
8. Sebagai supervisor, saya ingin melihat rak asal setiap pengeluaran material,
   supaya saya bisa menelusuri ketika ada keluhan.
9. Sebagai admin gudang, saya ingin menetapkan lokasi untuk stok yang belum punya
   lokasi, supaya tidak ada material yang "yatim lokasi".
10. Sebagai admin gudang, saya ingin melihat daftar stok yang belum punya lokasi,
    supaya saya tahu mana yang perlu ditata.
11. Sebagai admin gudang, saya ingin menonaktifkan lokasi tanpa menghapusnya,
    supaya riwayat pengeluaran lama tetap menunjuk lokasi yang sah.
12. Sebagai operator gudang, saya ingin pesan jelas ketika QR lokasi tidak dikenal,
    supaya saya bisa memastikan labelnya benar.
13. Sebagai pengguna dengan permission terbatas, saya ingin fitur lokasi tidak
    memberi akses tambahan, supaya batas otorisasi tetap utuh.

## Objective

Memberi sistem kemampuan mengenali lokasi rak material, mencatatnya pada
penerimaan dan pengeluaran, serta menyediakan cara menata stok yang belum punya
lokasi.

Pengguna: admin gudang, operator penerimaan, dan operator gudang pengguna APK.

Ukuran keberhasilan: setiap material yang dikeluarkan memiliki rak asal yang
tercatat, dan ketidaksesuaian rak terlihat sebagai peringatan tanpa menghentikan
pekerjaan operator.

## Technical Decisions

### Master lokasi

- Tabel master baru `locations` dengan `code` (unik), `name`, `is_active`, dan
  kolom audit yang sama dengan master lain.
- **Lokasi dinonaktifkan, bukan dihapus**, agar dokumen lama tetap menunjuk lokasi
  yang sah.
- Master dikelola di web pada modul Master, mengikuti pola master yang sudah ada
  (index/create/store/edit/update/destroy) dan dilindungi policy.

### Label QR lokasi

- Mengikuti pola label mesin yang sudah ada: halaman cetak per lokasi pada
  `/locations/{location}/label`.
- Isi QR berupa JSON dengan bentuk yang sama seperti label mesin:

```
{ "type": "location", "location_id": <int>, "location_code": "<code>", "location_name": "<name>" }
```

- APK meresolusi hasil pindai lewat endpoint baru yang memakai pola
  `machines/resolve` yang sudah ada.

### Penyimpanan lokasi

- **Lokasi disimpan di `incoming_receives.location_code`**, memakai kolom yang
  sudah ada. Alasan: nol perubahan skema untuk data penerimaan, dan secara arti
  memang "lokasi material dari penerimaan itu".
- Kolom tetap bertipe teks, tetapi **divalidasi harus ada di master lokasi** saat
  diisi. Tidak dijadikan foreign key agar riwayat lama tidak rusak bila lokasi
  dinonaktifkan.
- Konsekuensi yang diterima: memindahkan material antar rak berarti mengubah data
  penerimaan. Untuk iterasi ini memadai.

### Pencatatan saat pengeluaran

- Dokumen `material_issues` mendapat kolom baru `location_code` untuk mencatat rak
  yang dipindai saat pengeluaran.
- **Perubahan skema ini disetujui secara eksplisit** karena tanpa kolom ini tidak
  ada tempat mencatat hasil pindai.

### Perilaku validasi

- Lokasi yang dipindai **dicatat selalu**, dan **tidak memblokir** pengeluaran.
- Bila lokasi berbeda dari `location_code` pada penerimaan material, APK
  menampilkan **peringatan** dan pengeluaran tetap boleh dilanjutkan.
- Bila tag material belum punya lokasi sama sekali, pindai tetap dicatat tanpa
  peringatan.
- Alasan tidak memblokir: satu data rak yang basi tidak boleh menghentikan
  produksi. Operator dilaporkan lewat data, bukan dihalangi.

### Setup Lokasi

- Layar web untuk menetapkan lokasi pada penerimaan yang belum punya, sekaligus
  menampilkan daftar stok yang belum berlokasi.
- Ditempatkan di web, bukan APK, karena pekerjaan ini administratif dan lebih
  nyaman dengan tabel dan filter.
- **Tidak ada backfill data.** Saat PRD ini ditulis, stok dengan qty lebih dari nol
  berjumlah **nol baris**, sehingga tidak ada data lama yang perlu ditata. Layar ini
  disiapkan untuk celah yang muncul kemudian.

### Permission

- Permission baru untuk master lokasi mengikuti pola master lain, dan didaftarkan
  pada seeder peran yang sudah ada.
- Pindai lokasi di APK memakai permission pengeluaran stok yang sudah ada; tidak
  menambah permission baru untuk pemakaian di lapangan.

### Kontrak API

```
POST /api/locations/resolve

request : { location_code: string } | { location_id: int }
response: { ok, data: { id, location_code, location_name } }
          404 bila lokasi tidak ditemukan atau tidak aktif
```

```
POST /api/work-orders/{workOrder}/release   (diperluas)

request : { ..., location_code: string|null }   // opsional
```

`location_code` bersifat **opsional** agar klien lama tetap bisa memanggil endpoint
ini tanpa perubahan. Nilainya divalidasi terhadap master lokasi bila dikirim.

## Project Structure

    erp_gci/database/migrations/       -> Tabel locations + kolom material_issues.location_code
    erp_gci/app/Models/                -> Model Location
    erp_gci/app/Http/Controllers/      -> CRUD master lokasi + label QR + Setup Lokasi
    erp_gci/app/Http/Controllers/Api/  -> resolve lokasi
    erp_gci/app/Policies/              -> Policy lokasi
    erp_gci/tests/Feature/             -> Test master, label, setup, dan API

    erp_gci_mobile/lib/models/         -> Model lokasi hasil resolve
    erp_gci_mobile/lib/features/issue/ -> Langkah scan lokasi
    erp_gci_mobile/test/               -> Test widget dan model

## Commands

    Test API:    php artisan test --compact
    Lint PHP:    vendor/bin/pint --dirty --format agent
    Build web:   npm run build
    Test APK:    flutter test
    Lint APK:    flutter analyze

## Testing Strategy

Feature test PHP membuktikan:

- Master lokasi menolak kode duplikat.
- Lokasi nonaktif tidak bisa di-resolve.
- Label lokasi dapat dicetak dan memuat JSON dengan bentuk yang benar.
- Penerimaan menolak lokasi yang tidak ada di master.
- Pengeluaran mencatat `location_code` hasil pindai.
- Pengeluaran tetap berhasil ketika lokasi berbeda dari data penerimaan.
- Pengeluaran tanpa `location_code` tetap berhasil (kompatibel dengan klien lama).
- Setup Lokasi menetapkan lokasi pada penerimaan yang belum punya.
- Pengguna tanpa permission tetap ditolak.

Test Flutter membuktikan pemetaan model lokasi dan bahwa langkah scan lokasi
menampilkan peringatan tanpa memblokir.

## Boundaries

- **Always:** jalankan test PHP dan `flutter analyze` sebelum selesai; validasi
  kode lokasi terhadap master; jaga policy dan permission; sediakan teks tiga
  bahasa untuk APK.
- **Ask first:** perubahan skema di luar `locations` dan
  `material_issues.location_code`; menambah dependency; mengubah alur penerimaan
  yang sudah ada; mengubah alur release.
- **Never:** menjadikan lokasi syarat mutlak pengeluaran; menghapus lokasi yang
  sudah dipakai dokumen; melemahkan otorisasi; mengubah data produksi sebagai efek
  samping; menghapus test tanpa persetujuan.

## Out of Scope

- Perpindahan material antar rak sebagai transaksi tersendiri.
- Pelacakan lokasi per subspart bila berbeda dari material acuan.
- Cetak massal label lokasi.
- Denah atau peta gudang.
- Perhitungan kapasitas rak.
- Lokasi per mesin atau lini produksi (QR mesin sudah ada dan terpisah).

## Success Criteria

- [ ] Master lokasi dapat dikelola dari web dengan kode unik.
- [ ] Setiap lokasi dapat dicetak label QR-nya.
- [ ] QR lokasi dapat di-resolve oleh APK dan mengembalikan kode serta nama.
- [ ] Lokasi nonaktif ditolak saat resolve.
- [ ] Penerimaan menyimpan lokasi pada `incoming_receives.location_code`.
- [ ] Penerimaan menolak lokasi yang tidak ada di master.
- [ ] Pengeluaran mencatat lokasi hasil pindai pada dokumen pengeluaran.
- [ ] Pengeluaran tetap berhasil ketika lokasi berbeda, disertai peringatan.
- [ ] Pengeluaran tanpa lokasi tetap berhasil.
- [ ] Layar Setup Lokasi dapat menetapkan lokasi pada penerimaan yang belum punya.
- [ ] Lokasi yang sudah dipakai tidak dapat dihapus, hanya dinonaktifkan.
- [ ] Pengguna tanpa permission tetap ditolak.
- [ ] Test PHP, Pint, build web, `flutter test`, dan `flutter analyze` lulus.

## Open Questions

1. **Perpindahan rak.** PRD ini mengubah data penerimaan ketika material pindah
   rak. Bila perpindahan sering terjadi, perlu transaksi perpindahan tersendiri
   yang mencatat riwayat.
2. **Satu material, beberapa rak.** PRD ini mengasumsikan satu tag berada di satu
   lokasi. Bila satu tag dipecah ke beberapa rak, perlu aturan tersendiri.
3. **Sumber kode lokasi.** PRD ini tidak menetapkan pola penomoran kode lokasi.
   Bila ada standar pabrik, perlu diikuti saat pengisian master.
4. **Peringatan lokasi berbeda.** PRD ini memilih peringatan tanpa blokir. Bila
   ternyata ketidaksesuaian sering terjadi, perlu ditinjau apakah perlu eskalasi.
