# PRD: Material Scan Context (Material Tracker)

Module id: `material-scan-context`
Inisiatif: `docs/prd/material-tracker-clarity.md`

## Problem Statement

Operator produksi membuka layar issue material di APK dan melihat nama material
yang **tidak bisa discan sama sekali**. Yang tampil adalah main part dari BOM,
padahal main part tidak pernah memegang stok — stok selalu berada pada substitute.
Operator jadi melihat material yang salah, lalu harus menebak-nebak sendiri
substitute mana yang benar-benar tersedia di gudang.

Masalah kedua: daftar rekomendasi tag hanya menampilkan nomor tag dan qty, tanpa
invoice maupun supplier. Di gudang, operator memegang material fisik dan
mencocokkannya lewat invoice; tanpa informasi itu rekomendasi tidak bisa
diverifikasi dan operator harus membuka dokumen lain. Ukuran material pun tidak
ditampilkan, sehingga tidak ada cara memastikan part yang diambil memang ukuran
yang diminta.

## Solution

APK menampilkan **material yang benar-benar akan discan** — yaitu subspart hasil
alokasi planner, atau substitute aktif yang punya stok bila planner belum
mengalokasikan. Setiap material menampilkan nomor part, nama, **ukuran part**, dan
satuan, beserta qty bagiannya bila berasal dari alokasi.

Daftar tag yang bisa discan menampilkan **tag, invoice, supplier, qty tersedia,
satuan, dan tanggal terima**, diurutkan FIFO sehingga tag yang datang lebih dulu
muncul lebih awal. Seluruh tag yang tersedia ditampilkan, bukan hanya empat
teratas. Main part BOM tidak pernah ditampilkan sebagai **material yang discan**.

## User Stories

1. Sebagai operator produksi, saya ingin melihat subspart yang benar-benar
   dialokasikan planner, supaya saya tidak mencari material yang salah.
2. Sebagai operator produksi, saya ingin main part BOM tidak ditampilkan sebagai
   material, supaya saya tidak mencoba men-scan part yang tidak punya stok.
3. Sebagai operator produksi, saya ingin melihat ukuran part pada tiap material,
   supaya saya yakin part yang saya ambil memang ukurannya benar.
4. Sebagai operator produksi, saya ingin melihat nomor part dan nama material
   dengan jelas, supaya saya bisa mencocokkannya dengan label fisik.
5. Sebagai operator produksi, saya ingin melihat qty bagian tiap material hasil
   alokasi, supaya saya tahu berapa yang harus discan per part.
6. Sebagai operator produksi, saya ingin melihat invoice pada tiap rekomendasi
   tag, supaya saya bisa mencocokkannya dengan dokumen yang saya pegang.
7. Sebagai operator produksi, saya ingin melihat supplier pada tiap rekomendasi
   tag, supaya saya bisa membedakan asal material yang mirip.
8. Sebagai operator produksi, saya ingin rekomendasi tag urut FIFO berdasarkan
   kedatangan, supaya material yang lebih dulu datang dipakai lebih dulu.
9. Sebagai operator produksi, saya ingin seluruh tag yang tersedia ditampilkan,
   supaya tag yang benar tidak tersembunyi di balik batas empat baris.
10. Sebagai operator produksi, saya ingin melihat sisa stok tersedia per tag,
    supaya saya tahu tag mana yang stoknya cukup.
11. Sebagai operator produksi, saya ingin tahu bila sebuah item belum
    dialokasikan planner, supaya saya paham kondisi item itu dan bisa memakai
    substitute yang tersedia.
12. Sebagai operator produksi, saya ingin tahu bila tidak ada satu pun material
    berstok, supaya saya melapor alih-alih menebak atau menunggu tanpa arah.
13. Sebagai operator produksi, saya ingin item yang sudah terpenuhi tetap terlihat
    beserta qty yang sudah discan, supaya saya tidak mengulang scan.
14. Sebagai operator produksi, saya ingin satu material bisa dipenuhi dari
    beberapa part sekaligus, supaya alokasi sebagian dari planner tetap bisa
    dijalankan.
15. Sebagai planner, saya ingin APK menghormati alokasi yang saya buat, supaya
    keputusan material tetap berada di tangan saya.
16. Sebagai pengguna dengan permission terbatas, saya ingin perubahan ini tidak
    memberi akses tambahan, supaya batas otorisasi tetap utuh.
17. Sebagai operator produksi, saya ingin pesan kesalahan yang jelas ketika
    konteks material gagal dimuat, supaya saya bisa mencoba lagi.

## Objective

Menampilkan konteks material yang akurat dan dapat diverifikasi di layar issue
APK, sehingga operator hanya melihat material berstok beserta identitas tag yang
lengkap.

Pengguna: operator produksi pengguna APK Material Tracker.

Ukuran keberhasilan: pada layar issue, operator melihat subspart berstok beserta
ukuran, invoice, supplier, dan tag — dan tidak pernah melihat main part BOM
sebagai material yang harus discan.

## Technical Decisions

### Sumber material yang ditampilkan

Aturan berikut mengikat dan menggantikan perilaku sekarang:

1. **Main part BOM tidak pernah ditampilkan sebagai material yang discan** — bukan
   sebagai judul kartu item, bukan sebagai pilihan yang bisa dipindai. Yang tetap
   boleh: menampilkannya sebagai **baris kebutuhan** di papan material harian,
   karena di sana perannya adalah kebutuhan, bukan barang yang discan. Batas ini
   ditetapkan pada revisi 2026-09-25 setelah tampilan berubah menjadi berbasis
   material; lihat `docs/prd/material-daily-board.md`.
2. **Bila planner sudah mengalokasikan** (`work_order_item_allocations`),
   material yang ditampilkan adalah part-part alokasi tersebut, masing-masing
   dengan qty bagiannya.
3. **Bila belum ada alokasi**, material yang ditampilkan adalah substitute aktif
   yang punya stok tersedia.
4. **Bila tidak ada satu pun material berstok**, item ditandai
   "belum ada material berstok" dan tetap memuat daftar material sah, supaya
   operator tahu harus melapor.

Alasan aturan ini: main part BOM adalah acuan BOM dan tidak memegang stok; seluruh
stok berada pada substitute aktif. Menampilkan main part menghasilkan material
yang tidak bisa discan.

### Keputusan lain yang mengikat

- **Ukuran material memakai `parts.size`** milik part yang ditampilkan, dengan
  `work_order_items.size` hanya sebagai cadangan bila `parts.size` kosong.
  Alasannya: operator mencocokkan part fisik yang benar-benar akan discan, dan
  ukuran BOM bisa berbeda dari ukuran substitute yang dialokasikan. Hanya satu
  angka ukuran ditampilkan agar kartu tetap terbaca.
- **Urutan FIFO memakai `received_at` sebagai kunci utama** (tanggal material
  benar-benar masuk stok, berasal dari ATA receive), **`invoice_date` sebagai
  pemecah seri**, lalu `id`. Alasannya: `received_at` adalah dasar ledger stok dan
  urutan yang sudah dipakai web; `invoice_date` bisa kosong sehingga tidak aman
  sebagai kunci utama.
- **Seluruh tag tersedia ditampilkan**, menggantikan pemotongan empat tag di APK.
- **Cakupan rekomendasi mengikuti material yang ditampilkan.** Material sah lain
  tetap bisa discan karena validasi server tidak diubah.
- **Konteks satu item bisa memuat beberapa material.** Item yang dipenuhi dari
  beberapa part tetap ditampilkan sebagai satu kartu dengan daftar material.
- **Qty yang sudah di-issue dikirim per item dan per material.** Angka ini berasal
  dari dokumen issue material, bukan dari hasil produksi, sehingga APK dapat
  membedakan material yang sudah keluar dari scan yang belum disimpan. Field ini
  diminta oleh modul `tracker-dashboard`; lihat `docs/prd/tracker-dashboard.md`.
- **Tidak ada perubahan skema database.** Invoice dan supplier sudah tersimpan di
  `material_issue_items`, dan tag berasal dari `part_stocks` yang sudah menyimpan
  `receive_id` dan `received_at`.
- **Tidak ada perubahan pada validasi scan.** `allowed_parts` tetap dikirim agar
  APK bisa memvalidasi part hasil scan; server tetap penentu akhir.

### Kontrak API

`GET /api/work-orders/{workOrder}/release-context` diperluas, bukan diganti.
Field lama (`part`, `child_part_name`, `allowed_parts`, `recommended_tags`,
`required`, `consumed`, `remaining`, `uom`, `process`, `machine`, `sequence`)
tetap dikirim agar klien lama tidak putus.

Field tambahan per item:

```
stock_state : 'available' | 'none'
size        : string|null          // parts.size, fallback work_order_items.size
issued_qty  : float                // material yang sudah di-issue untuk item ini
materials   : [
  {
    part_id       : int
    part_number   : string|null
    part_name     : string|null
    size          : string|null
    uom           : string|null
    source        : 'allocation' | 'substitute'
    allocation_qty: float|null     // qty bagian dari alokasi planner
    issued_qty    : float          // material yang sudah di-issue untuk part ini
    stock_qty     : float
    available_qty : float          // stock_qty - booking aktif
    tags          : [
      {
        tag        : string|null
        invoice    : string|null
        supplier   : string|null
        qty        : float         // qty tersedia pada tag
        uom        : string|null
        received_at: string|null
        part_id    : int
        part_number: string|null
      }
    ]
  }
]
```

Urutan `materials`: part alokasi dulu (urut qty terbesar), lalu substitute
berstok. Urutan `tags`: `received_at` naik, lalu `invoice_date` naik, lalu `id`.

## Project Structure

    erp_gci/app/Http/Controllers/Api/ -> Endpoint APK (release-context)
    erp_gci/tests/Feature/Api/        -> Feature test API (prior art)
    erp_gci/docs/prd/                 -> PRD dan capability map

    erp_gci_mobile/lib/models/        -> Model respons API
    erp_gci_mobile/lib/features/issue/-> Layar issue dan kartu item
    erp_gci_mobile/test/              -> Test Flutter

## Commands

    Test API:    php artisan test --compact tests/Feature/Api/MaterialIssueApiTest.php
    Test penuh:  php artisan test --compact
    Lint PHP:    vendor/bin/pint --dirty --format agent
    Test APK:    flutter test
    Lint APK:    flutter analyze

## Testing Strategy

Feature test API membuktikan perilaku lewat respons JSON, bukan detail
implementasi. Prior art: `tests/Feature/Api/MaterialIssueApiTest.php`.

Yang dibuktikan:

- Item dengan alokasi menampilkan part alokasi, bukan main part BOM.
- Main part BOM tidak pernah muncul di `materials`.
- Item tanpa alokasi menampilkan substitute aktif yang berstok.
- Item tanpa material berstok menghasilkan `stock_state` bernilai `none`.
- Qty bagian alokasi ikut terkirim.
- Ukuran memakai `parts.size`, dan jatuh ke `work_order_items.size` bila kosong.
- Tag memuat `invoice` dan `supplier`.
- Tag diurutkan FIFO: `received_at` naik, lalu `invoice_date`, lalu `id`.
- Seluruh tag terkirim tanpa dipotong empat.
- Qty tersedia sudah dikurangi booking aktif.
- Field respons lama tetap ada.
- Otorisasi `issue` tetap berlaku.

Test Flutter membuktikan pemetaan model baru dan bahwa kartu menampilkan material
berstok beserta invoice, supplier, dan ukuran.

## Boundaries

- **Always:** jalankan `php artisan test --compact tests/Feature/Api/MaterialIssueApiTest.php`
  dan `flutter analyze` sebelum selesai; jaga `Gate::authorize`; pertahankan field
  respons lama; pakai query batch agar tidak menimbulkan N+1.
- **Ask first:** mengubah skema database; menambah dependency; mengubah rumus
  stok, booking, atau konsumsi; mengubah validasi scan di server.
- **Never:** menampilkan main part BOM sebagai material yang discan (sebagai baris
  kebutuhan di papan material tetap boleh); melemahkan otorisasi;
  mengubah data produksi sebagai efek samping; menghapus test tanpa persetujuan;
  memakai jam perangkat sebagai penentu tanggal.

## Out of Scope

- Mengubah cara planner mengalokasikan material di web.
- Mengubah aturan FIFO konsumsi stok (`bookFromTag`, `availableFifo`).
- Mengubah rumus booking atau konsumsi.
- Menambah endpoint baru khusus APK.
- Menampilkan riwayat konsumsi per material di APK.
- Mengubah validasi part yang sah untuk sebuah item.
- Menambah foto atau gambar material.

## Success Criteria

- [ ] Material hasil alokasi planner muncul di `materials`, bukan main part BOM.
- [ ] Main part BOM tidak pernah muncul sebagai material yang discan pada item yang punya alokasi.
- [ ] Item tanpa alokasi menampilkan substitute aktif yang berstok.
- [ ] Item tanpa material berstok menghasilkan `stock_state` bernilai `none`.
- [ ] `allocation_qty` terkirim untuk material hasil alokasi.
- [ ] `issued_qty` terkirim per item dan per material.
- [ ] `size` memakai `parts.size`, dengan cadangan `work_order_items.size`.
- [ ] Setiap tag memuat `invoice` dan `supplier`.
- [ ] Tag diurutkan FIFO berdasarkan `received_at`, lalu `invoice_date`, lalu `id`.
- [ ] Seluruh tag tersedia terkirim tanpa dipotong empat.
- [ ] `available_qty` sudah dikurangi booking aktif.
- [ ] `allowed_parts` dan field respons lama tetap terkirim.
- [ ] Pengguna tanpa permission tetap ditolak.
- [ ] APK menampilkan material berstok beserta ukuran, invoice, dan supplier.
- [ ] Feature test API, test penuh, Pint, `flutter test`, dan `flutter analyze` lulus.

## Open Questions

1. **Main part yang kebetulan punya stok.** Aturan saat ini tidak menampilkan main
   part sama sekali. Bila di lapangan ada main part yang benar-benar berstok dan
   harus bisa discan, perlu aturan tersendiri. Belum diperlukan sekarang.
2. **Jumlah tag sangat banyak.** PRD ini memutuskan menampilkan seluruh tag. Bila
   suatu material punya puluhan tag, perlu diputuskan apakah perlu pemuatan
   bertahap. Belum diperlukan sekarang.
3. **Item tanpa alokasi dan tanpa substitute aktif.** Saat ini ditandai
   "belum ada material berstok". Bila kasus ini sering terjadi, perlu alur
   pelaporan ke planner. Dikesampingkan untuk iterasi ini.
