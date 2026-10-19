# PRD: Saran Tag Berbasis Rak & FIFO (Issue-Out APK)

## Problem Statement

Operator gudang menyiapkan material untuk satu WO lewat APK, tetapi alur yang ada
sekarang memaksa mereka scan rak berulang kali untuk setiap material dan
mengembalikan ke tahap awal setiap kali pindah material. Akibatnya menyiapkan
material satu WO terasa lama, padahal jumlah tag per WO maksimal sekitar sepuluh
dan jarang lebih. Operator tidak tahu tag mana yang harus diambil dan di rak mana
barang itu berada tanpa mencarinya sendiri.

## Solution

Saat operator membuka satu WO, APK langsung menampilkan daftar material beserta
tag yang disarankan, diurutkan FIFO, lengkap dengan rak tempat tag itu berada dan
qty-nya. Operator tinggal pergi ke rak tersebut dan memindai tag yang muncul —
tanpa scan rak lebih dulu, tanpa menghitung qty manual.

Scan rak tetap tersedia sebagai opsi penyaring: operator yang sedang berdiri di
satu rak bisa memindainya untuk menyaring daftar agar hanya menampilkan tag yang
berada di rak itu.

## User Stories

1. Sebagai operator gudang, saya ingin melihat daftar tag yang disarankan untuk
   material sebuah WO, supaya saya tahu harus mengambil tag yang mana.
2. Sebagai operator gudang, saya ingin melihat rak tempat tag berada, supaya saya
   bisa langsung menuju lokasi tanpa mencari.
3. Sebagai operator gudang, saya ingin memindai rak untuk menyaring daftar tag di
   rak itu, supaya saya hanya melihat tag di depan saya.
4. Sebagai operator gudang, saya ingin qty tag terisi otomatis dari stok, supaya
   saya tidak perlu menghitung atau mengetik.
5. Sebagai operator gudang, saya ingin pindah ke material berikutnya tanpa mundur
   ke tahap awal, supaya menyiapkan satu WO selesai dalam beberapa scan.
6. Sebagai operator gudang, saya ingin saran FIFO ditandai, supaya saya tahu tag
   mana yang sebaiknya keluar lebih dulu.
7. Sebagai operator gudang, saya ingin tetap boleh memindai tag valid apa pun,
   supaya saran tidak menghambat ketika fisik di rak berbeda dari dugaan.
8. Sebagai operator gudang, saya ingin diberi tahu jika rak yang saya pindai tidak
   berisi tag untuk material ini, supaya saya tidak menunggu atau bingung.
9. Sebagai warehouse, saya ingin jejak per-tag tetap tercatat, supaya FIFO dan
   validasi WO tetap terjaga.
10. Sebagai supervisor, saya ingin alur issue tetap task-centric, supaya operator
    tetap dibimbing aplikasi.

## Objective

Mempercepat penyiapan material untuk satu WO di APK Warehouse dengan menampilkan
saran tag FIFO beserta lokasi rak, menghilangkan scan rak berulang dan langkah
mundur antar-material, tanpa mengubah aturan booking atau memaksa urutan tertentu.

## Technical Decisions

- **Qty tag diambil dari stok tag** (`PartStock.qty` dikurangi booking aktif),
  bukan input manual dan bukan master packing baru.
- **Saran tag dikirim endpoint release-context** yang sudah ada. Backend menambah
  `location_code` pada setiap tag di `recommended_tags`/`materials[].tags`,
  bersumber dari `incoming_receives.location_code` tag terkait.
- **Urutan FIFO** mengikuti aturan yang sudah ada di `PartStockTagService`:
  `received_at` ASC (nulls last), lalu `invoice_date`, lalu `id`. Tag paling awal
  ditandai sebagai saran, bukan kewajiban.
- **Scan tag biasa**: satu tag = satu kemasan fisik. Qty terisi otomatis dari data
  stok tag. Jejak per-tag tetap agar FIFO dan validasi WO tidak hilang.
- **Scan rak jadi opsi penyaring**, bukan langkah wajib. Operator dapat memindai
  rak untuk memfilter daftar agar hanya menampilkan tag di rak itu. Bila rak tidak
  berisi tag untuk material ini, tampilkan notice berisi rak dan jumlah tag yang
  sebenarnya tersedia.
- **Pindah material tanpa mundur**: setelah satu material selesai di-issue, lanjut
  ke material berikutnya tanpa mengulang scan rak dan tanpa kembali ke tahap awal.
- **Tidak memaksa FIFO**: tag valid apa pun tetap diterima server. Saran hanya
  bantuan urutan.

## Project Structure

Perubahan pada struktur yang sudah ada; tidak ada direktori baru.

```
app/Services/ReleaseContextService.php   -> tag membawa location_code
app/Services/PartStockTagService.php     -> sertakan location_code pada setiap tag
lib/features/issue/guided_issue_screen.dart -> daftar saran + opsi scan rak
lib/models/release_context.dart          -> MaterialTag membawa locationCode
```

## Commands

    Test:  php artisan test --filter=ReleaseContext
    Test:  flutter test
    Lint:  vendor/bin/pint --dirty --format agent
    Build: npm run build
    Build APK: flutter build apk --release

## Testing Strategy

- Test API: setiap tag pada release-context membawa `location_code` yang benar
  sesuai data penerimaan, dan urutan FIFO tetap.
- Test API: tag tanpa lokasi (mis. stok hasil produksi) tetap dikirim dengan
  `location_code` null tanpa error.
- Test Flutter: daftar saran menampilkan tag, rak, dan qty; tag FIFO teratas
  ditandai.
- Test Flutter: scan rak menyaring daftar; rak tanpa tag menampilkan notice.
- Test Flutter: pindah material tidak mengulang scan rak dan tidak kembali ke
  tahap awal.
- Test regresi: aturan booking, validasi WO, dan release per material tetap lulus.
- Prior art: `tests/Feature/Api/MaterialIssueApiTest.php`, `scanner_polish_test.dart`.

## Boundaries

- **Always:**
  - Qty tag berasal dari stok tag, bukan input manual.
  - Jejak per-tag tercatat untuk setiap issue.
  - Lokasi hanya info/saringan; tidak memblokir scan tag valid.
- **Ask first:**
  - Perubahan bentuk respons release-context (menambah field pada tag).
  - Penghapusan langkah scan rak dari alur wajib.
- **Never:**
  - Memaksa urutan FIFO atau menolak tag valid di luar saran.
  - Menambah master packing baru.
  - Mengubah aturan booking atau validasi material WO di server.

## Out of Scope

- Master packing baru atau input jumlah kemasan manual.
- Release sekaligus semua material dalam satu dokumen.
- Mengubah konsep task-centric Warehouse.
- Menambah endpoint API baru; cukup memperluas release-context yang ada.

## Success Criteria

- [ ] release-context mengirim `location_code` pada setiap tag di saran material.
- [ ] APK menampilkan daftar saran tag per material dengan rak dan qty, urut FIFO.
- [ ] Tag FIFO teratas ditandai sebagai saran.
- [ ] Scan rak menyaring daftar tanpa menjadi langkah wajib.
- [ ] Rak tanpa tag untuk material terkait menampilkan notice.
- [ ] Pindah material tidak mengulang scan rak dan tidak mundur ke tahap awal.
- [ ] Qty tag terisi otomatis; tidak ada input manual.
- [ ] Test ERP dan Flutter lulus tanpa regresi pada issue/release.

## Open Questions

- Apakah notice "rak tanpa tag untuk material ini" perlu sekalian menyebut rak
  alternatif yang memuat tag, atau cukup menyebut rak yang dipindai tidak berisi?
- Apakah filter rak perlu bertahan sampai material berikutnya, atau direset tiap
  ganti material?
