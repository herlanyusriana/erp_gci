# PRD: Monitoring Issue Out to Production APK

## Problem Statement

Issue Out material sudah dilakukan operator melalui APK dan transaksi berhasil
tersimpan di ERP. Namun kepala gudang dan PPIC belum memiliki layar operasional
yang mudah ditemukan dan selalu mutakhir untuk mengetahui material apa yang
baru dikeluarkan, untuk WO mana, oleh siapa, menggunakan tag apa, serta dalam
jumlah berapa. Daftar Material Issue yang ada masih berfungsi sebagai riwayat
dasar dan belum memberi ringkasan maupun pembaruan real-time.

## Solution

Tingkatkan halaman Material Issue yang sudah ada menjadi dashboard Monitoring
Issue Out. Halaman menampilkan ringkasan aktivitas harian, daftar transaksi yang
dapat dicari dan difilter, serta pembaruan otomatis melalui WebSocket segera
setelah APK berhasil membuat transaksi. Pengguna dapat membuka detail dan
mencetak bon, tetapi tidak dapat mengubah atau membatalkan transaksi dari
halaman monitoring.

## User Stories

1. Sebagai kepala gudang, saya ingin melihat jumlah Issue Out hari ini agar saya dapat memantau aktivitas pengeluaran material.
2. Sebagai kepala gudang, saya ingin melihat jumlah WO dan tag yang dilayani agar saya mengetahui beban operasional gudang.
3. Sebagai PPIC, saya ingin melihat transaksi berdasarkan WO agar saya dapat memastikan material untuk produksi sudah dikeluarkan.
4. Sebagai pengguna berwenang, saya ingin melihat total qty per UOM agar nilai dengan satuan berbeda tidak digabung secara menyesatkan.
5. Sebagai pengguna berwenang, saya ingin transaksi baru muncul otomatis agar saya tidak perlu memuat ulang halaman.
6. Sebagai pengguna berwenang, saya ingin transaksi baru disorot dan diinformasikan melalui toast agar perubahan mudah dikenali.
7. Sebagai pengguna berwenang, saya ingin mengetahui status koneksi real-time agar saya tahu apakah tampilan masih mutakhir.
8. Sebagai pengguna berwenang, saya ingin koneksi WebSocket tersambung kembali secara otomatis agar gangguan sementara tidak menghentikan monitoring.
9. Sebagai pengguna berwenang, saya ingin memuat ulang data secara manual ketika koneksi bermasalah agar saya tetap dapat memperoleh data terbaru.
10. Sebagai kepala gudang atau PPIC, saya ingin memfilter riwayat berdasarkan periode tanggal agar saya dapat meninjau aktivitas pada hari tertentu.
11. Sebagai kepala gudang atau PPIC, saya ingin mencari nomor issue, WO, tag, part, operator, penerima, invoice, atau supplier agar transaksi tertentu cepat ditemukan.
12. Sebagai pengguna berwenang, saya ingin memfilter operator dan status agar daftar sesuai dengan kebutuhan pemeriksaan.
13. Sebagai pengguna berwenang, saya ingin membuka detail transaksi agar saya dapat memeriksa seluruh material dan tag yang dikeluarkan.
14. Sebagai pengguna berwenang, saya ingin mencetak bon transaksi agar dokumen fisik tetap tersedia untuk proses operasional.
15. Sebagai pengguna tanpa permission Issue Out, saya tidak ingin data pengeluaran material dapat diakses agar informasi operasional tetap terlindungi.
16. Sebagai administrator VPS, saya ingin panduan pemasangan WebSocket agar monitoring real-time dapat dijalankan dengan aman di production.

## Objective

Menyediakan satu layar monitoring real-time bagi kepala gudang, PPIC berwenang,
dan management untuk mengawasi transaksi Issue Out berhasil dari APK. Fitur
dianggap berhasil ketika transaksi baru tampil segera tanpa refresh halaman,
ringkasan tetap akurat, riwayat dapat ditelusuri, dan aksesnya dibatasi dengan
permission yang sesuai.

## Technical Decisions

- Halaman Material Issue yang sudah ada ditingkatkan; tidak dibuat sumber data atau halaman monitoring kedua.
- Tile **Monitoring Issue Out** harus tersedia dan mudah dikenali pada module Outgoing.
- Halaman awal menampilkan data tanggal bisnis hari ini.
- Ringkasan terdiri dari jumlah Issue Out, jumlah WO unik, jumlah tag, dan total qty yang dikelompokkan per UOM.
- Qty dengan UOM berbeda tidak boleh dijumlahkan menjadi satu grand total.
- Filter tanggal dan ringkasan menggunakan `issue_date`; urutan transaksi terbaru menggunakan `created_at`.
- Perhitungan tanggal monitoring menggunakan zona waktu `Asia/Jakarta` (WIB). Perubahan zona waktu global aplikasi tidak dilakukan diam-diam dan memerlukan persetujuan terpisah jika ternyata dibutuhkan.
- Pencarian mencakup nomor issue, nomor WO, tag, part, operator, penerima, invoice, dan supplier.
- Filter terstruktur mencakup rentang tanggal, operator, dan status.
- Daftar tetap menggunakan pagination dan mempertahankan filter pada navigasi halaman.
- Transaksi baru dikirim melalui Laravel broadcasting dengan Laravel Reverb di server dan Laravel Echo di browser.
- Channel real-time harus private/authenticated dan hanya dapat diikuti pengguna dengan permission `stock.issue`.
- Policy index, detail, print, dan channel authorization harus menggunakan kontrak permission yang konsisten.
- Event hanya disiarkan setelah transaksi database berhasil commit sehingga browser tidak menerima transaksi yang kemudian rollback.
- Retry API dengan idempotency key yang sama tidak boleh menghasilkan baris atau notifikasi ganda.
- Event memuat identitas transaksi dan data ringkasan yang diperlukan untuk memperbarui layar: id, nomor issue, tanggal issue, waktu pembuatan, WO, operator, penerima, status, jumlah item/tag, dan total per UOM.
- Ketika event sesuai dengan filter aktif, baris baru muncul paling atas, disorot sementara, kartu ringkasan diperbarui, dan toast ditampilkan tanpa suara.
- Jika event tidak sesuai dengan filter aktif, event tidak dimasukkan ke daftar terfilter.
- Status koneksi ditampilkan sebagai Terhubung, Menghubungkan, atau Terputus.
- Browser melakukan reconnect otomatis dan menyediakan refresh manual. Polling setiap satu atau dua detik tidak digunakan sebagai fallback.
- Halaman monitoring bersifat read-only. Aksi yang tersedia hanya membuka detail dan mencetak bon.
- APK tidak diubah; keberhasilan submit API tetap menjadi konfirmasi bagi APK, sedangkan backend menyiarkan event ke dashboard.
- Infrastruktur Reverb untuk development dan production harus didokumentasikan, termasuk process manager, reverse proxy WebSocket, TLS, firewall, environment variables, restart, dan pemeriksaan kesehatan.

## Project Structure

    app/Events/                         -> Event broadcasting transaksi Issue Out berhasil
    app/Http/Controllers/               -> Query monitoring, filter, ringkasan, detail, dan print
    app/Models/                         -> Model Material Issue dan item yang sudah ada
    app/Policies/                       -> Otorisasi halaman dan data Material Issue
    routes/                             -> Route web dan authorization channel broadcasting
    resources/js/Pages/Outgoing/        -> Dashboard, detail, dan launcher Outgoing
    resources/js/Components/            -> Komponen UI reusable bila dibutuhkan
    resources/js/i18n/catalogs/         -> Teks id, en, dan ko
    resources/js/types/                 -> Kontrak TypeScript data monitoring
    tests/Feature/                      -> Test halaman, filter, ringkasan, permission, dan event
    tests/Feature/Api/                  -> Regresi alur Issue Out dari APK
    docs/prd/                           -> PRD fitur
    docs/                               -> Panduan operasional WebSocket di VPS

## Commands

    Install PHP dependencies: composer install
    Install frontend dependencies: npm install
    Build frontend: npm run build
    Test affected feature: php artisan test --compact tests/Feature/MaterialIssueWebTest.php tests/Feature/Api/MaterialIssueApiTest.php
    Test full suite: vendor/bin/pest
    Format PHP: vendor/bin/pint --dirty --format agent
    Development application: composer run dev
    Development WebSocket: php artisan reverb:start

## Testing Strategy

- Pertahankan `MaterialIssueWebTest` sebagai prior art untuk akses index, detail, print, dan launcher.
- Pertahankan `MaterialIssueApiTest` sebagai regresi bahwa submit APK atomik, tervalidasi, dan idempotent.
- Tambahkan feature test untuk kartu ringkasan, pengelompokan qty per UOM, rentang tanggal, seluruh target pencarian, filter operator, dan filter status.
- Tambahkan authorization test yang membuktikan pengguna dengan `stock.issue` dapat mengakses monitoring dan pengguna tanpa permission ditolak.
- Tambahkan test channel authorization untuk mencegah subscription tanpa permission.
- Tambahkan test bahwa event disiarkan setelah transaksi berhasil dan tidak disiarkan ketika transaksi rollback.
- Tambahkan test bahwa retry dengan idempotency key yang sama tidak menyiarkan transaksi bisnis kedua.
- Verifikasi payload event hanya berisi data yang diperlukan dashboard.
- Jalankan build TypeScript/Vue untuk memverifikasi kontrak tipe, i18n, dan integrasi Echo.
- Lakukan pengujian browser terhadap koneksi, event baru, highlight, toast, reconnect, filter aktif, serta indikator status.
- Pengujian menggunakan database PostgreSQL yang sudah dimigrasi dan di-seed sesuai strategi repository; test Feature tetap berjalan dalam transaksi.

## Boundaries

- **Always:** gunakan transaksi Issue Out berhasil sebagai sumber kebenaran; terapkan `Gate::authorize(...)`; gunakan private channel; validasi filter; eager-load relasi; gunakan `ilike` untuk pencarian PostgreSQL; jaga idempotensi; sediakan terjemahan id/en/ko; gunakan token warna tema; jalankan test terkait, Pint, dan build frontend.
- **Ask first:** perubahan zona waktu global aplikasi; dependency baru selain Reverb/Echo yang telah disetujui; perubahan schema; perubahan konfigurasi CI; deployment aktual atau perubahan service, firewall, reverse proxy, dan secret pada VPS.
- **Never:** menyediakan pembatalan atau koreksi transaksi dari dashboard; menyiarkan data melalui public channel; menjumlahkan UOM berbeda; menyimpan kredensial VPS dalam repository; mengubah APK tanpa persetujuan; menghapus atau melemahkan test agar build hijau.

## Out of Scope

- Pembatalan, koreksi, atau penghapusan Material Issue.
- Monitoring percobaan scan atau submit APK yang gagal.
- Export rekap Excel atau PDF.
- Perubahan UI atau perilaku APK.
- Polling data setiap satu atau dua detik.
- Notifikasi suara.
- Analitik tren jangka panjang dan dashboard management lanjutan.
- Deployment langsung ke VPS; cakupan saat ini adalah implementasi dan panduan pemasangan.
- Perubahan zona waktu global seluruh ERP.

## Success Criteria

- [ ] Tile Monitoring Issue Out dapat ditemukan dari module Outgoing.
- [ ] Pengguna dengan permission `stock.issue` dapat membuka index, detail, dan print; pengguna lain ditolak.
- [ ] Halaman default menampilkan transaksi dengan `issue_date` hari ini berdasarkan WIB.
- [ ] Ringkasan menampilkan jumlah issue, WO unik, tag, dan qty per UOM secara akurat.
- [ ] Pengguna dapat memfilter tanggal, operator, dan status.
- [ ] Pengguna dapat mencari nomor issue, WO, tag, part, operator, penerima, invoice, dan supplier.
- [ ] Transaksi Issue Out berhasil muncul otomatis tanpa reload penuh melalui private WebSocket.
- [ ] Transaksi baru yang sesuai filter muncul paling atas, disorot, dan menghasilkan toast tanpa suara.
- [ ] Ringkasan diperbarui tanpa menjumlahkan UOM berbeda.
- [ ] Retry API dengan idempotency key yang sama tidak menampilkan transaksi atau toast ganda.
- [ ] Status koneksi terlihat dan reconnect otomatis bekerja setelah koneksi sementara terputus.
- [ ] Refresh manual tetap tersedia saat real-time bermasalah.
- [ ] Monitoring tidak menyediakan aksi pembatalan, koreksi, atau hapus.
- [ ] Existing detail dan print tetap berfungsi.
- [ ] Panduan VPS menjelaskan service Reverb, reverse proxy, TLS, firewall, environment variables, restart, dan health check.
- [ ] Test feature terkait, Pint, dan build frontend berhasil.

## Open Questions

Tidak ada. Seluruh keputusan produk yang diperlukan untuk perencanaan telah disepakati.
