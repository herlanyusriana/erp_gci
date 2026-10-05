# PRD: Produksi Per Step (WIP → FG) — APK Material Tracker

## Problem Statement

Operator produksi di lantai saat ini tidak bisa mencatat hasil kerja mereka secara langsung. Laporan hasil produksi per step (WIP) hanya bisa dilakukan lewat web yang tidak terjangkau dari area mesin. Akibatnya:

1. Operator harus meninggalkan mesin atau menulis di kertas — rawan salah catat dan hilang.
2. Stok WIP antar-step tidak terlihat realtime — operator tidak tahu apakah stok dari step sebelumnya cukup untuk memulai kerja.
3. Stok gudang tidak berkurang saat material diterima di mesin — penerimaan material hanya catatan, tanpa eksekusi stok.

## Solution

APK Material Tracker mendapatkan **layar input hasil produksi per step** yang memungkinkan operator:

1. Memilih WO `in_progress` yang sudah sampai di mesinnya (material sudah diterima).
2. Melihat step untuk WO itu.
3. Input **Good** (qty lolos) dan **NG** (No Good, qty cacat) untuk setiap **periode pencatatan**.
4. Sistem otomatis: WIP/FG masuk stok, konsumsi RM dari stok yang sudah diterima di mesin, dan **stok gudang benar-benar berkurang** saat penerimaan material.
5. Setelah submit, notifikasi step berikutnya muncul sebagai referensi.
6. Pencatatan dilakukan per **periode** (kelipatan waktu, misal setiap jam: 09.30, 10.30, 11.30...). Bukan per shift, bukan realtime. Waktu periode bisa diatur (30 menit, 1 jam, 2 jam, shift, dll).

## User Stories

1. Sebagai operator mesin, saya ingin memilih WO `in_progress` yang materialnya sudah sampai di mesin saya, supaya saya bisa mulai bekerja.
2. Sebagai operator mesin, saya ingin melihat step mana yang tersedia untuk WO itu (hanya step pertama, atau step berikutnya setelah step sebelumnya selesai), supaya saya tidak bingung urutan kerja.
3. Sebagai operator mesin, saya ingin input Good (qty lolos) dan NG (qty cacat) saat lapor hasil, supaya data produksi tercatat dengan benar.
4. Sebagai operator mesin, saya ingin sistem otomatis menghitung sisa stok WIP untuk step berikutnya, supaya saya tahu apakah saya bisa lanjut atau harus menunggu.
5. Sebagai operator mesin, saya ingin mendapat notifikasi "Step selesai. Lanjut ke step berikutnya?" setelah submit hasil, supaya alur kerja tidak terputus.
6. Sebagai operator mesin, saya ingin melihat stok WIP yang tersedia di papan material, supaya saya bisa merencanakan kerja.
7. Sebagai warehouse, saya ingin stok gudang benar-benar berkurang saat material discan dan diterima di mesin, supaya stok fisik dan sistem sinkron.
8. Sebagai supervisor, saya ingin Complete WO tetap dilakukan lewat web, supaya ada otorisasi sebelum status berubah.

## Objective

APK Material Tracker dikembangkan untuk mencatat hasil produksi per step (WIP → FG) langsung dari lantai produksi, dengan input Good/NG, stok gudang yang benar-benar berkurang saat material diterima di mesin, dan stok WIP antar-step yang terlihat realtime — tanpa harus bolak-balik ke web.

## Technical Decisions

1. **Penerimaan material di mesin eksekusi stok gudang**. `ProductionReceiptService::confirm()` memanggil:
   - `consumeFifoByUom()` — stok gudang (`part_stocks.qty`) benar-benar turun saat material discan di mesin.
2. **Pencatatan hasil per step dilakukan per PERIODE** (kelipatan waktu, misal setiap jam: 09.30, 10.30, 11.30...), bukan realtime. Step boleh dikerjakan paralel dalam satu periode.
3. **Konsumsi WIP terjadi di periode berikutnya**. Contoh:
   - Periode 1: step Potong dicatat → WIP hasil Potong masuk stok.
   - Periode 2: step Lipat dicatat (konsumsi WIP Potong) → **baru di sini** WIP Potong berkurang.
   - Papan WIP tetap menampilkan stok WIP hasil Potong setelah periode 1 — operator bisa lihat ketersediaan. Tapi pengurangan aktual terjadi saat step konsumen dicatat.
4. **Validasi stok WIP**: saat step N+1 dicatat, sistem cek apakah WIP step N (yang sudah tercatat) mencukupi. Jika tidak cukup, peringatan — tidak blokir.
5. **Input Good + NG** — endpoint yang sudah ada, field `qty_good` untuk Good, `qty_reject` untuk NG.
6. **Papan WIP** — filter tipe part `WIP` di material board. Menampilkan stok WIP hasil pencatatan yang sudah masuk (belum terpakai).
7. **Reminder pencatatan** — APK mengirim notifikasi alarm **5 menit sebelum setiap periode** berakhir (misal periode 10.30, alarm jam 10.25) untuk mengingatkan operator mencatat hasil produksi. Interval alarm mengikuti periode pencatatan yang diatur.
8. **Complete WO** dilakukan otomatis oleh sistem saat **reconcile balance** — total material & WIP yang terpakai (Good + NG di semua step) sudah mencapai target konversi BOM. Atau manual oleh supervisor via web. APK tidak menyediakan Complete WO.
9. **WO Closed** = hasil reconcile balance sudah sesuai BOM.

## Project Structure

Tidak ada perubahan struktur direktori. Perubahan pada file yang sudah ada:

- `app/Services/ProductionReceiptService.php` → tambah `consumeFifoByUom`
- `app/Http/Controllers/Api/ProductionReceiptController.php` → response `next_step`
- `app/Services/MaterialBoardService.php` → filter WIP
- `app/Http/Controllers/Api/MaterialBoardApiController.php` → parameter `type`
- `lib/features/result/results_screen.dart` → notif step berikutnya
- `lib/providers/issue_provider.dart` → state next_step

## Commands

    Build (APK): flutter build apk --release
    Test (APK):  flutter test
    Test (ERP):  php artisan test
    Lint (ERP):  vendor/bin/pint
    Build (ERP): npm run build

## Testing Strategy

- Test API: verifikasi `confirm()` mengurangi stok dengan `consumeFifoByUom`
- Test API: verifikasi response `next_step` setelah submit result
- Test Flutter: widget test untuk layar input Good/NG
- Test Flutter: notifikasi step berikutnya muncul dan tombol "Lanjut" berfungsi

## Boundaries

- **Always:**
  - Input Good/NG wajib diisi (minimal Good > 0, NG boleh 0).
  - Stok gudang harus turun setelah confirm penerimaan.
  - Step N+1 hanya bisa dimulai jika step N sudah ada hasil.
- **Ask first:**
  - Perubahan ke `consumeFifoByUom` — perlu review dampak stok riil.
  - Penambahan filter WIP di MaterialBoardApiController.
- **Never:**
  - Complete WO dari APK.
  - Edit hasil yang sudah disubmit.
  - Cancel WO dari APK.

## Out of Scope

- Complete WO — tetap via web.
- Cancel WO — tetap via web.
- Cetak label WIP/FG.
- Edit hasil produksi yang sudah disubmit.
- Dashboard produksi (rekap per shift/hari).

## Success Criteria

- [ ] `POST /api/receipts/confirm` mengurangi stok gudang via `consumeFifoByUom`
- [ ] `POST /api/work-orders/{wo}/results` mengembalikan `next_step` (null jika step terakhir)
- [ ] Layar input Good/NG di APK berfungsi — submit → sukses → notif step berikutnya
- [ ] Tab "WIP" di papan material menampilkan stok WIP antar-step
- [ ] Complete WO tetap hanya dari web
- [ ] Semua test backend lulus (259+)
- [ ] Semua test APK lulus (87+)

## Open Questions

- Apakah operator bisa input Good=0 (semua NG)? Mungkin butuh konfirmasi "Yakin 0 good?"