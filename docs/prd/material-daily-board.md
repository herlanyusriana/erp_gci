# PRD: Material Daily Board (Material Tracker)

Module id: `material-daily-board`
Inisiatif: `docs/prd/material-tracker-clarity.md`

## Problem Statement

Operator gudang bekerja dari **material**, bukan dari nomor WO. Yang dia pegang
adalah lembaran atau komponen fisik, dan pertanyaannya sederhana: "material ini
hari ini harus keluar berapa, dan untuk WO mana saja?" APK sekarang hanya bisa
menjawab sebaliknya — daftar WO lebih dulu, material belakangan. Operator harus
membuka WO satu per satu untuk menyusun gambaran kebutuhan material sehari,
padahal itu justru informasi pertama yang dia butuhkan saat menyiapkan barang.

Akibatnya, kebutuhan material sehari tidak pernah terlihat utuh. Operator tidak
tahu bahwa satu material dipakai beberapa WO sekaligus, tidak tahu total yang
harus dikeluarkan hari ini, dan tidak bisa membandingkannya dengan rencana besok
saat menyiapkan lebih awal.

## Solution

APK menampilkan **papan material harian**: satu baris per material, dengan kolom
**D** dan **D+1**. Setiap kolom menunjukkan **qty kebutuhan** material itu pada
tanggal tersebut dan tombol **DETAILS**.

Material dikenali dari **nama + model**, dan ukurannya ikut ditampilkan karena
satu nama dengan model sama bisa punya beberapa ukuran berbeda. Di balik tombol
DETAILS tersedia dua hal: **daftar WO** yang membutuhkan material itu beserta qty
masing-masing, dan **daftar subspart** yang bisa discan lengkap dengan **invoice**
dan stok tersedianya.

Dari situ operator memilih WO dan langsung masuk ke alur scan material.

## User Stories

1. Sebagai operator gudang, saya ingin melihat kebutuhan material per tanggal,
   supaya saya tahu apa yang harus disiapkan hari ini.
2. Sebagai operator gudang, saya ingin melihat kolom D dan D+1 berdampingan,
   supaya saya bisa menyiapkan kebutuhan besok lebih awal.
3. Sebagai operator gudang, saya ingin satu baris per material, supaya saya tidak
   perlu menggabungkan sendiri beberapa WO yang memakai material yang sama.
4. Sebagai operator gudang, saya ingin mengenali material dari nama dan modelnya,
   supaya cocok dengan sebutan yang dipakai di lapangan.
5. Sebagai operator gudang, saya ingin melihat ukuran material, supaya saya tidak
   salah ambil di antara material yang namanya mirip.
6. Sebagai operator gudang, saya ingin melihat total qty kebutuhan material hari
   itu dalam satuan materialnya, supaya saya tahu berapa yang harus keluar.
7. Sebagai operator gudang, saya ingin membuka DETAILS untuk melihat WO mana saja
   yang memakai material itu, supaya saya paham pekerjaan di baliknya.
8. Sebagai operator gudang, saya ingin melihat qty kebutuhan per WO, supaya saya
   tahu porsi tiap WO terhadap total.
9. Sebagai operator gudang, saya ingin melihat subspart yang tersedia beserta
   invoice-nya, supaya saya bisa mencocokkan dengan barang fisik.
10. Sebagai operator gudang, saya ingin memilih WO dari daftar DETAILS, supaya
    saya bisa langsung masuk ke alur scan.
11. Sebagai operator gudang, saya ingin material yang kebutuhannya sudah tuntas
    hilang dari papan, supaya daftar hanya memuat yang masih tersisa.
12. Sebagai operator gudang, saya ingin melihat berapa yang sudah dikeluarkan dan
    berapa sisanya per material, supaya saya tahu progres tanpa membuka WO.
13. Sebagai operator gudang, saya ingin bisa mencari material berdasarkan nama,
    nomor part, atau model, supaya saya cepat menemukannya.
14. Sebagai operator gudang, saya ingin pesan jelas ketika tidak ada kebutuhan
    material pada tanggal itu, supaya saya tidak mengira aplikasi rusak.
15. Sebagai operator gudang, saya ingin pesan gagal muat disertai tombol coba
    lagi, supaya saya bisa memulihkan sendiri.
16. Sebagai planner, saya ingin angka papan dihitung dari jadwal yang saya isi di
    Production Plan, supaya jadwal tetap menjadi satu-satunya sumber kebenaran.
17. Sebagai planner, saya ingin porsi kebutuhan material mengikuti qty WO hari itu,
    supaya angka material tidak memakai qty WO penuh ketika pekerjaannya dibagi.
18. Sebagai pengguna dengan permission terbatas, saya ingin papan ini tidak memberi
    akses tambahan, supaya batas otorisasi tetap utuh.

## Objective

Menyediakan papan material harian di APK yang menjawab "material apa, berapa, dan
untuk WO mana" dalam sekali lihat, untuk tanggal berjalan dan besoknya.

Pengguna: operator gudang dan operator produksi pengguna APK Material Tracker.

Ukuran keberhasilan: operator dapat membaca total kebutuhan satu material untuk
hari ini dan besok, lalu membuka WO di baliknya, tanpa menghitung manual dari
daftar WO.

## Technical Decisions

### Definisi baris

- **Baris = material acuan BOM** — yaitu `child_part_id` dari item WO yang bersifat
  leaf (child-nya bukan WIP internal), mengikuti definisi leaf yang sudah dipakai
  alur release.
- **Kunci baris = pasangan (part, satuan).** Satu material bisa diminta dalam
  satuan berbeda oleh WO berbeda; memisahkannya menjaga satuan tetap konsisten dan
  mencegah penjumlahan KGM dengan PCS.
- **Label = nama material + model**, dengan **ukuran** ditampilkan terpisah.
  Alasannya: satu nama dengan model sama bisa punya beberapa ukuran.

Catatan penting: **material acuan BOM kini tampil sebagai baris kebutuhan.** Ini
melunakkan aturan di `material-scan-context` yang melarang main part BOM
ditampilkan. Yang tetap dilarang adalah menampilkannya sebagai **material yang
discan** — peran itu tetap milik subspart berstok.

### Perhitungan qty

Qty kebutuhan material pada sebuah tanggal adalah penjumlahan, atas seluruh WO yang
dijadwalkan tanggal itu, dari:

```
kebutuhan hari = qty_required × (planned_qty ÷ qty WO)
```

- `qty_required` adalah kebutuhan untuk **qty WO penuh**, sehingga harus diskalakan
  dengan porsi hari itu.
- `planned_qty` adalah qty jadwal WO hari itu dari modul `daily-planned-wo-feed`.
- Bila `qty WO` bernilai nol, WO tersebut dilewati (tidak boleh membagi nol).

Rasio ini dipakai agar satuan tetap benar: kebutuhan KGM tetap KGM, kebutuhan PCS
tetap PCS.

### Kolom D dan D+1

- **D** memakai aturan jadwal tanggal berjalan.
- **D+1** memakai aturan jadwal yang sama, dibaca untuk tanggal besok.
- Kolom D+2 **tidak** ditampilkan, agar tabel tetap terbaca di layar ponsel.

### Isi DETAILS

DETAILS dibuka per material per tanggal, dan memuat dua bagian:

1. **Daftar WO** — nomor WO, status, qty jadwal hari itu, kebutuhan material untuk
   WO itu, yang sudah dikeluarkan, dan sisanya. Setiap WO dapat dipilih untuk masuk
   ke alur scan.
2. **Subspart tersedia** — subspart aktif dari material tersebut beserta stok
   tersedia dan tag FIFO-nya lengkap dengan **invoice** dan **supplier**.

Alasan dipisah: daftar papan tetap ringan walau satu material punya banyak
subspart dan tag, sementara invoice tetap satu ketukan dari baris material.

### Keputusan lain yang mengikat

- **Material dengan kebutuhan tuntas disembunyikan**, mengikuti pola modul
  `daily-planned-wo-feed` yang menyembunyikan WO dengan material tuntas.
- **Sumber jadwal tidak berubah.** Papan membaca Production Plan; `planned_date`
  pada WO tetap bukan penentu.
- **Papan hanya memuat material yang benar-benar dibutuhkan.** Material tanpa WO
  terjadwal pada tanggal itu tidak muncul.
- **Material Subcon tetap dihitung.** Papan mengikuti aturan leaf yang sama dengan
  alur release, dan alur release **tidak** mengecualikan material Subcon: material
  yang dikirim ke subkon memang keluar dari gudang, jadi tetap di-issue. Yang tidak
  masuk papan adalah **mesin** Subcon, dan itu urusan papan Production Plan, bukan
  papan material.

  Catatan revisi 2026-09-25: draf awal PRD ini menyatakan "Subcon tidak masuk
  papan". Klaim itu keliru — hasil pemeriksaan data menunjukkan `bom_items` memuat
  22 baris ber-`source` Subcon, dan alur release membooking materialnya.
- **Tidak ada perubahan skema database.** Seluruh data sudah tersedia di
  Production Plan, item WO, alokasi, stok, dan dokumen issue.

### Kontrak API

Endpoint baru, tidak mengubah endpoint lama.

```
GET /api/material-board

data: {
  date        : string        // tanggal pabrik berjalan, Y-m-d
  next_date   : string        // tanggal + 1 hari, Y-m-d
  materials: [
    {
      part_id        : int
      part_number    : string|null
      part_name      : string|null
      model          : string|null
      size           : string|null
      uom            : string|null
      day_qty        : float      // kebutuhan material pada tanggal D
      day_issued_qty : float
      day_remaining  : float
      day_wo_count   : int
      next_day_qty        : float
      next_day_issued_qty : float
      next_day_remaining  : float
      next_day_wo_count   : int
    }
  ]
}
```

```
GET /api/material-board/{part}/details?date=Y-m-d

data: {
  date     : string
  material : { part_id, part_number, part_name, model, size, uom, required_qty, issued_qty, remaining_qty }
  work_orders: [
    {
      id, wo_no, status, qty,
      planned_qty,        // qty jadwal WO pada tanggal itu
      required_qty,       // kebutuhan material untuk WO itu pada tanggal itu
      issued_qty, remaining_qty,
      fg_part: { part_number, part_name, model } | null
    }
  ]
  substitutes: [
    {
      part_id, part_number, part_name, size, uom,
      available_qty,
      tags: [ { tag, invoice, supplier, qty, uom, received_at } ]
    }
  ]
}
```

Parameter `date` opsional pada details; bila tidak dikirim, server memakai tanggal
pabrik berjalan. Otorisasi memakai aturan yang sudah ada pada endpoint APK
(`viewAny` untuk WorkOrder), sehingga tidak menambah permukaan akses baru.

## Project Structure

    erp_gci/app/Http/Controllers/Api/ -> Endpoint papan material
    erp_gci/app/Services/             -> Perhitungan kebutuhan material harian
    erp_gci/tests/Feature/Api/        -> Feature test API (prior art)

    erp_gci_mobile/lib/models/        -> Model respons API
    erp_gci_mobile/lib/features/      -> Layar papan material dan DETAILS
    erp_gci_mobile/test/              -> Test widget dan model

## Commands

    Test API:    php artisan test --compact tests/Feature/Api/
    Test penuh:  php artisan test --compact
    Lint PHP:    vendor/bin/pint --dirty --format agent
    Test APK:    flutter test
    Lint APK:    flutter analyze

## Testing Strategy

Fokus pada perilaku yang teramati lewat respons JSON dan model Flutter.

Feature test API membuktikan:

- Material muncul sebagai baris dengan qty kebutuhan hari itu.
- Kolom D+1 memakai tanggal besok, bukan tanggal hari ini.
- Qty kebutuhan memakai rasio porsi hari, bukan qty WO penuh.
- Satu material yang dipakai dua WO dijumlahkan menjadi satu baris.
- Material dengan kebutuhan tuntas tidak muncul.
- Material tanpa WO terjadwal tidak muncul.
- Material Subcon tetap muncul, mengikuti aturan leaf alur release.
- D+2 tidak muncul di respons.
- DETAILS memuat daftar WO beserta qty dan subspart beserta invoice.
- Otorisasi tetap berlaku untuk pengguna tanpa permission.

Test Flutter membuktikan pemetaan model dan tampilan tabel dua kolom.

## Boundaries

- **Always:** jalankan test API dan `flutter analyze` sebelum menyatakan selesai;
  jaga otorisasi endpoint; pertahankan endpoint lama tanpa perubahan; pakai query
  batch agar tidak menimbulkan N+1.
- **Ask first:** mengubah skema database; menambah dependency; mengubah alur
  release atau rumus stok; mengubah papan Production Plan di web.
- **Never:** menampilkan material acuan BOM sebagai material yang discan;
  melemahkan otorisasi; mengubah data produksi sebagai efek samping; memakai jam
  perangkat sebagai penentu tanggal; menghapus test tanpa persetujuan.

## Out of Scope

- Kolom D+2 dan seterusnya.
- Mengubah cara planner mengisi target di papan Production Plan.
- Mengubah alur release, booking tag, atau rumus stok.
- Grafik, tren, atau laporan analitik material.
- Ekspor atau cetak papan material dari APK.
- Menambah bahasa di luar tiga yang sudah ada.

## Success Criteria

- [ ] Papan menampilkan satu baris per pasangan (material, satuan).
- [ ] Baris menampilkan nama material, model, dan ukuran.
- [ ] Kolom D dan D+1 menampilkan qty kebutuhan masing-masing tanggal.
- [ ] Qty kebutuhan memakai rasio porsi hari, bukan qty WO penuh.
- [ ] Satu material dari beberapa WO dijumlahkan menjadi satu baris.
- [ ] Material dengan kebutuhan tuntas tidak muncul.
- [ ] Material tanpa WO terjadwal tidak muncul.
- [ ] Material Subcon tetap muncul, mengikuti aturan leaf alur release.
- [ ] DETAILS memuat daftar WO beserta qty per WO.
- [ ] DETAILS memuat subspart beserta invoice dan supplier.
- [ ] WO pada DETAILS dapat dipilih menuju alur scan.
- [ ] Kondisi kosong dan gagal muat dibedakan dengan pesan yang jelas.
- [ ] Pengguna tanpa permission tetap ditolak.
- [ ] Feature test API, test penuh, Pint, `flutter test`, dan `flutter analyze` lulus.

## Open Questions

1. **Invoice langsung di baris papan.** PRD ini menaruh invoice di DETAILS agar
   papan tetap ringan. Bila Pak Arnold ingin invoice terlihat langsung di baris,
   perlu keputusan tersendiri tentang batas jumlah yang ditampilkan.
2. **Porsi kebutuhan per mesin.** PRD ini mengatribusikan kebutuhan material ke WO,
   bukan ke baris mesin, karena baris plan dan item WO tidak terhubung di skema.
   Bila nanti dibutuhkan porsi per mesin, perlu perubahan skema.
3. **Material tanpa subspart aktif.** Saat ini tetap muncul dengan daftar subspart
   kosong. Bila sering terjadi, perlu alur pelaporan ke planner.
4. **Nasib penghitung "WO menunggu dijadwalkan".** WO yang sudah release tetapi
   belum punya jadwal tidak muncul di baris material mana pun, sehingga papan ini
   tidak bisa menunjukkan keberadaannya. Sebelumnya penghitung itu tinggal di
   dashboard berbasis WO yang kini digantikan. Perlu diputuskan apakah penghitung
   itu ikut dipindah ke papan material atau ditampilkan terpisah. **Belum
   diputuskan pada PRD ini.**
