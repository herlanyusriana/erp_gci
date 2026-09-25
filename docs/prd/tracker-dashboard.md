# PRD: Tracker Dashboard — Layar Issue (Material Tracker)

Module id: `tracker-dashboard`
Inisiatif: `docs/prd/material-tracker-clarity.md`

Revisi 2026-09-25: modul ini **dipersempit** menjadi layar issue saja. Bagian
daftar/dashboard berbasis WO dipindahkan ke `material-daily-board`, karena
tampilan tingkat atas kini berbasis material. Alur issue bertambah langkah
**scan lokasi rak**.

## Problem Statement

Setelah operator memilih sebuah WO, layar issue menampilkan angka yang
**mencampur dua hal berbeda**. Angka "Ter-scan" hanya menghitung scan pada sesi
itu, sehingga material yang sudah dikeluarkan sebelumnya tidak terlihat sama
sekali. Operator bisa mengira sebuah item belum dikerjakan padahal materialnya
sudah keluar, lalu mengulang scan tag yang sama. Begitu satu item punya beberapa
material dengan banyak tag, kartu memanjang tanpa hierarki dan makin sulit dibaca.

Selain itu, pengeluaran material tidak meninggalkan jejak rak asal. Ketika ada
keluhan atau kekurangan stok, tidak ada cara menelusuri dari mana material diambil.

## Solution

Layar issue memisahkan angka secara tegas menjadi **Kebutuhan**, **Sudah
di-issue** (permanen, dari dokumen pengeluaran), **Ter-scan sesi ini** (belum
disimpan), dan **Sisa**. Tiap material menampilkan progresnya sendiri, dan daftar
tag dibuka per material agar kartu tetap ringkas meski datanya lengkap.

Setelah material yang dipindai cocok dengan kebutuhan WO, operator memindai
**QR lokasi rak**. Lokasi itu dicatat pada dokumen pengeluaran, dan bila berbeda
dari data penerimaan, operator melihat peringatan — tanpa dihalangi.

## User Stories

1. Sebagai operator gudang, saya ingin angka kebutuhan, sudah di-issue, ter-scan
   sesi ini, dan sisa dipisah tegas, supaya saya tidak mengulang scan material
   yang sudah keluar.
2. Sebagai operator gudang, saya ingin melihat progres tiap material, supaya saya
   tahu material mana yang masih kurang.
3. Sebagai operator gudang, saya ingin melihat qty bagian alokasi tiap material,
   supaya saya tahu target per part.
4. Sebagai operator gudang, saya ingin membuka daftar tag per material, supaya
   kartu tetap ringkas dan saya fokus ke satu material.
5. Sebagai operator gudang, saya ingin daftar tag menampilkan invoice, supplier,
   dan qty tersedia, supaya saya bisa mencocokkan dengan fisik.
6. Sebagai operator gudang, saya ingin melihat qty yang sudah ter-scan pada sesi
   berjalan beserta tombol ubah dan hapus, supaya saya bisa mengoreksi sebelum
   mengirim.
7. Sebagai operator gudang, saya ingin diberi tahu ketika material yang saya pindai
   cocok dengan kebutuhan WO, supaya saya yakin barangnya benar.
8. Sebagai operator gudang, saya ingin diminta memindai lokasi rak setelah material
   cocok, supaya rak asal tercatat tanpa mengetik.
9. Sebagai operator gudang, saya ingin melihat peringatan bila rak yang saya pindai
   berbeda dari data penerimaan, supaya saya sadar ada ketidaksesuaian.
10. Sebagai operator gudang, saya ingin tetap bisa melanjutkan ketika rak berbeda,
    supaya satu data basi tidak menghentikan pekerjaan saya.
11. Sebagai operator gudang, saya ingin melihat rak yang sudah saya pindai sebelum
    mengirim, supaya saya bisa memperbaikinya bila salah.
12. Sebagai operator gudang, saya ingin pesan jelas ketika QR lokasi tidak dikenal,
    supaya saya bisa memastikan labelnya benar.
13. Sebagai operator gudang, saya ingin pesan gagal muat disertai tombol coba lagi,
    supaya saya bisa memulihkan sendiri.
14. Sebagai operator gudang, saya ingin tetap diberi tahu saat tidak ada koneksi,
    supaya saya tahu mengapa data tidak muncul.
15. Sebagai pengguna dengan permission terbatas, saya ingin layar ini tidak memberi
    akses tambahan, supaya batas otorisasi tetap utuh.

## Objective

Membuat layar issue APK menampilkan progres material secara jujur dan mencatat rak
asal material tanpa menghambat operator.

Pengguna: operator gudang pengguna APK Material Tracker.

Ukuran keberhasilan: operator dapat membedakan material yang sudah dikeluarkan dari
yang baru dipindai, dan setiap pengeluaran meninggalkan jejak lokasi rak.

## Technical Decisions

### Struktur layar issue

- **Empat angka per item**, dipisah tegas:
  - `Kebutuhan` — qty yang diminta untuk item itu.
  - `Sudah di-issue` — material yang sudah keluar, dihitung dari dokumen
    pengeluaran.
  - `Ter-scan sesi ini` — scan yang belum disimpan.
  - `Sisa` — kebutuhan dikurangi sudah di-issue dan ter-scan sesi ini.
- **Progres per material** menampilkan qty bagian alokasi dan berapa yang sudah
  terpenuhi.
- **Daftar tag dibuka per material** lewat tombol, bukan langsung terbuka semua.
  Seluruh tag tetap tersedia.
- **Baris scan sesi berjalan** menampilkan tag, invoice, qty, serta aksi ubah qty
  dan hapus.

### Alur issue yang baru

```
pilih WO (dari DETAILS papan material)
    -> muat konteks material
    -> pindai material
    -> cocok?  -- tidak --> tolak dengan pesan jelas
         |
        ya
         v
    pindai lokasi rak
         -> catat lokasi
         -> berbeda dari data penerimaan? --> tampilkan peringatan (tidak memblokir)
         v
    kirim pengeluaran (lokasi ikut terkirim)
```

- **Kecocokan material memakai aturan validasi server yang sudah ada** — material
  harus termasuk material sah untuk item itu. Tidak ada aturan baru; yang
  ditambahkan hanya tampilan konfirmasinya.
- **Lokasi bersifat opsional pada pengiriman.** Bila operator tidak memindai
  lokasi, pengeluaran tetap berhasil, mengikuti keputusan di
  `docs/prd/location-scan.md`.
- **Lokasi yang dipindai ditampilkan sebelum kirim**, supaya operator bisa
  mengoreksi bila salah rak.

### Keputusan lain yang mengikat

- **Memakai token tema resmi** yang sudah ada. Tidak menambah warna di luar token.
- **Seluruh teks baru tersedia dalam tiga bahasa** (Indonesia, Inggris, Korea).
- **Warna tidak menjadi satu-satunya penanda.** Status sisa dan peringatan lokasi
  juga ditandai teks atau ikon.
- **Tidak mengubah alur scan material, alur release, maupun rumus stok.**

### Interface Requirements

| Kebutuhan | Kontrak | PRD pemilik |
|---|---|---|
| `location_code` opsional saat pengeluaran | `POST /api/work-orders/{workOrder}/release` | `location-scan` |
| Resolusi QR lokasi | `POST /api/locations/resolve` | `location-scan` |

### Yang dipindahkan keluar dari modul ini

- Blok ringkasan tiga angka, kartu WO, dan daftar WO menunggu dijadwalkan
  **dipindahkan** ke `material-daily-board`.
- Kolom D+1 yang sebelumnya masuk Out of Scope kini **wajib** dan dimiliki
  `material-daily-board`. Keputusan lama itu dibatalkan di capability map.

## Project Structure

    erp_gci_mobile/lib/features/issue/ -> Layar issue, kartu item, langkah lokasi
    erp_gci_mobile/lib/providers/      -> State sesi scan
    erp_gci_mobile/lib/models/         -> Model respons API
    erp_gci_mobile/lib/core/l10n/      -> Katalog teks tiga bahasa
    erp_gci_mobile/lib/core/theme/     -> Token warna resmi
    erp_gci_mobile/test/               -> Test widget dan model

    erp_gci/docs/prd/                  -> PRD dan capability map

## Commands

    Test APK:   flutter test
    Lint APK:   flutter analyze
    Test API:   php artisan test --compact
    Lint PHP:   vendor/bin/pint --dirty --format agent

## Testing Strategy

Fokus pada perilaku yang teramati di layar dan model, bukan detail implementasi.

Test Flutter membuktikan:

- Layar issue memisahkan "Sudah di-issue" dari "Ter-scan sesi ini".
- Sisa item dihitung dari kebutuhan dikurangi sudah di-issue dan ter-scan sesi ini.
- Progres per material dihitung dari qty bagian dan qty terpenuhi.
- Daftar tag tersembunyi sampai tombol per material ditekan, lalu memuat seluruh tag.
- Konfirmasi kecocokan material tampil setelah pindai berhasil.
- Langkah scan lokasi tampil setelah material cocok.
- Peringatan tampil ketika lokasi berbeda, dan pengiriman tetap dapat dilanjutkan.
- Pengiriman tetap berhasil ketika lokasi tidak dipindai.
- Pesan gagal muat menampilkan tombol coba lagi.
- Banner offline tetap muncul saat tidak ada koneksi.
- Seluruh teks baru ada di ketiga bahasa.

`flutter analyze` menjadi gerbang wajib karena proyek memakai `flutter_lints`.

## Boundaries

- **Always:** jalankan `flutter analyze` dan `flutter test` sebelum menyatakan
  selesai; pakai token tema resmi; sediakan teks tiga bahasa; jaga otorisasi di
  sisi server; pertahankan aksi ubah qty dan hapus pada baris scan.
- **Ask first:** menambah dependency; menambah warna atau token baru; mengubah
  alur scan material dan release; mengubah kontrak API di luar tabel Interface
  Requirements.
- **Never:** menampilkan "Ter-scan sesi ini" sebagai "Sudah di-issue"; memotong
  daftar tag; menjadikan lokasi syarat mutlak pengiriman; memakai warna sebagai
  satu-satunya penanda; melemahkan otorisasi; menghapus test tanpa persetujuan.

## Out of Scope

- Papan material harian dan kolom D/D+1 — milik `material-daily-board`.
- Blok ringkasan dan daftar WO menunggu dijadwalkan — dipindahkan ke
  `material-daily-board`.
- Master lokasi dan pencetakan label QR — milik `location-scan`.
- Grafik, tren, atau laporan analitik.
- Layar rekap historis atau ekspor dari APK.
- Menambah layar hasil produksi baru.
- Mode gelap.
- Menambah bahasa di luar tiga yang sudah ada.

## Success Criteria

- [ ] Layar issue memisahkan "Sudah di-issue" dan "Ter-scan sesi ini" sebagai dua angka berbeda.
- [ ] Sisa item dihitung dari kebutuhan dikurangi sudah di-issue dan ter-scan sesi ini.
- [ ] Tiap material menampilkan qty bagian alokasi dan progres terpenuhi.
- [ ] Daftar tag dibuka per material dan memuat seluruh tag tanpa dipotong.
- [ ] Baris scan sesi berjalan menyediakan aksi ubah qty dan hapus.
- [ ] Konfirmasi kecocokan material tampil setelah pindai berhasil.
- [ ] Langkah scan lokasi tampil setelah material cocok.
- [ ] Peringatan tampil ketika lokasi berbeda, dan pengiriman tetap dapat dilanjutkan.
- [ ] Pengiriman tanpa lokasi tetap berhasil.
- [ ] Lokasi yang dipindai terlihat sebelum pengiriman dikirim.
- [ ] Kondisi gagal memuat menampilkan tombol coba lagi.
- [ ] Banner offline tetap muncul saat tidak ada koneksi.
- [ ] Seluruh teks baru tersedia dalam tiga bahasa.
- [ ] Tidak ada warna di luar token tema resmi.
- [ ] `flutter test`, `flutter analyze`, dan `php artisan test --compact` lulus.

## Open Questions

1. **Bentuk akhir kartu item.** PRD ini memutuskan material ringkas dengan daftar
   tag yang dibuka per material. Bila operator justru lebih sering membuka tag,
   susunan ini perlu ditinjau ulang.
2. **Urutan langkah saat lokasi tidak dipindai.** PRD ini mengizinkan operator
   melewati langkah lokasi. Bila ternyata lokasi hampir selalu terlewat, perlu
   ditinjau apakah langkahnya perlu dibuat lebih menonjol.
3. **Urutan kartu item di layar issue.** PRD ini mempertahankan urutan item apa
   adanya. Bila operator ingin material yang kurang didahulukan, perlu aturan
   pengurutan tersendiri.
