# Implementation Plan: Saran Tag Berbasis Rak & FIFO

PRD: `docs/prd/issue-tag-suggestion.md`

## Overview

Menampilkan saran tag FIFO beserta lokasi rak pada layar issue-out APK, sehingga
operator cukup melihat saran, pergi ke rak, lalu memindai tag. Scan rak menjadi
opsi penyaring, bukan langkah wajib. Perpindahan antar material tidak lagi mundur
ke tahap awal.

## Architecture Decisions

- **Perubahan backend aditif**: `location_code`, `part_stock_id`, dan nullable
  `invoice_date` (`YYYY-MM-DD`) disertakan pada setiap tag di `release-context`.
  Tidak ada endpoint baru.
- **Qty dari stok tag**, sumbernya `PartStockTagService` yang sudah mengurutkan
  FIFO. Tidak ada input manual, tidak ada master packing.
- **Scan rak = filter**, bukan langkah wajib. Tidak memblokir tag valid di luar
  saran.
- **Jejak per-tag tetap**, sehingga booking dan validasi WO di server tidak
  berubah.

## Dependency Graph

```
PartStockTagService (location_code + part_stock_id + invoice_date)
    │
    └── ReleaseContextService (teruskan ke setiap tag)
            │
            └── GET /api/work-orders/{wo}/release-context
                    │
                    ├── Flutter MaterialTag / RecommendedTag (parse lokasi + tie keys)
                    │       │
                    │       └── Guided issue screen (daftar saran + filter rak)
                    │
                    └── Release per material (sudah selesai, tinggal disesuaikan)
```

## Task List

### Phase 1: Backend — Lokasi pada Tag

- [x] Task 1: Sertakan `location_code` pada setiap tag di `PartStockTagService`
- [x] Follow-up: expose `part_stock_id` dan nullable `invoice_date` untuk FIFO lintas part.

### Checkpoint: Backend
- [x] Release-context mengirim lokasi dan tie keys per tag; FIFO tetap.
- [x] `MaterialIssueApiTest`: 73 tes lulus (431 assertions), termasuk 2 tes lokasi sebelumnya.
- [x] Suite lengkap: 306 tes lulus (1746 assertions); Pint dirty dan build frontend lulus.

### Phase 2: Flutter — Model & Saran

- [x] Task 2: `MaterialTag` dan `RecommendedTag` membawa nullable `locationCode`
- [x] Task 3: Layar saran tag — daftar per material dengan rak dan qty, tanda FIFO

### Checkpoint: Saran Tampil
- [x] Pilih WO → daftar saran tag tampil dengan rak dan qty (widget test).
- [x] `flutter test --no-pub` lulus: 148 tes.

### Phase 3: Flutter — Filter Rak & Alur

- [x] Task 4: Scan rak sebagai filter; notice rak tanpa tag
- [x] Task 5: Pindah material tanpa mundur dan tanpa ulang scan rak

### Checkpoint: Alur Lengkap
- [x] Dua material di-release tanpa mundur ke tahap awal (widget test, repository fake).
- [x] Release per material tetap jalan; scan material lain tetap tersimpan.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Tag stok hasil produksi tidak punya `location_code` | Rendah | Kirim null; UI tampilkan "tanpa lokasi" |
| Filter rak menyembunyikan tag valid di rak lain | Sedang | Filter bersifat sementara + tombol bersihkan |
| Mengubah bentuk respons tag menabrak test lama | Sedang | Tambah field, jangan ubah/hapus field lama |

## Keputusan Implementasi Tasks 2–5

- Notice menyebut filter aktif dan lokasi alternatif beserta jumlah tag tersedia sebenarnya.
- Filter bertahan lintas material sampai dibersihkan; perilaku ini dijelaskan di UI ID/EN/KO.
- Filter hanya menyaring tampilan, tidak diisi ke `location_code` dokumen release.
- Tidak memanggil `load()` setelah release per material: kuantitas berhasil di-issue
  dicatat dalam sesi, baris material lain tetap ada, dan tag yang telah di-issue
  tidak disarankan lagi. Resolve dan submit server tetap memvalidasi stok aktual.
- FIFO digabung dari `materials[].tags`, bukan daftar main material BOM. Urutan:
  `received_at` ASC nulls last, `invoice_date` ASC nulls last, `part_stock_id` ASC.
  Tie lintas part kini memakai field backend yang sama persis. Untuk server lama
  tanpa tie keys, urutan sumber stabil dipertahankan setelah perbandingan tanggal
  yang tersedia; rak/nama tag tidak dipakai sebagai kunci pengurutan.
- Qty scan memakai stok dikurangi `booked`. Kebijakan menolak qty tag di atas
  sisa kebutuhan WO tetap berlaku; full-tag overissue belum disetujui.
- Bukti lokal: 148 tes Flutter lulus, 35 tes baru kumulatif; analyze tanpa error/warning
  baru, dengan 3 info baseline di luar scope. Tes memakai scanner injection;
  tidak ada klaim kamera fisik, panggilan produksi, atau build APK rilis.

## Follow-up: Exact Cross-part FIFO

- RED backend: 2 tes baru gagal karena `part_stock_id` belum ada dan daftar ID
  menghasilkan null; GREEN setelah penambahan 2 field aditif (45 assertions).
- RED mobile: widget timestamp sama lintas part memilih tag yang salah ketika
  tie-break stock ID dibutuhkan; model test gagal karena parser field kanonis
  belum ada. GREEN: focused model + suggestion 43 tes, suite penuh 137 tes.
- Tambahan follow-up: 2 tes backend dan 6 tes mobile (3 parser, 2 widget dengan
  invoice/ID berlawanan urutan sumber/alfabet, 1 fallback server lama).
- `npm run build` lulus; Vite masih memberi advisory chunk >500 kB.
- Booking, batas qty WO/overissue, release per material, dan endpoint default
  hertech yang sudah ada tidak diubah oleh follow-up ini.

## Blocker Review: Pending Release Tidak Boleh Berubah Payload

- [x] Sebelum request dikirim, simpan snapshot immutable yang mengikat key,
  work order, item ID per scan, tag/part ID, qty, dan lokasi dokumen.
- [x] Timeout/network, HTTP 408, dan 5xx menyisakan pending attempt. Request lain
  dengan payload berbeda ditolak sebelum API dipanggil; key tidak dirotasi.
- [x] Guided dan standard issue menyediakan pesan ID/EN/KO serta aksi Retry
  release sebelumnya. Selector, tag/qty editing, dan lokasi dikunci; `reset()`
  serta `load()` tidak menghapus pending attempt, termasuk saat request berjalan.
- [x] Retry memakai snapshot original. Hanya baris dan qty snapshot yang
  dikreditkan/dihapus setelah respons sukses; scan material lain tetap tersimpan.
- [x] Penolakan awal terkonfirmasi (400/401/403/404/405/409/422, bukan network)
  membuka edit kembali dengan key baru, sehingga payload berbeda tidak pernah
  menggunakan key attempt yang ditolak. Setelah pernah ambigu, penolakan pada retry tetap tidak
  menghapus pending attempt; hanya sukses yang menyelesaikannya.
- RED: fake server commit A lalu timeout; release B dengan key A sebelumnya
  diterima dan salah mengkredit B. Tes juga menemukan edit setelah 408/500 serta
  recovery UI yang belum tersedia. RED tambahan mengunci key baru setelah
  penolakan awal 409/422. GREEN: 11 tes baru pending-release lulus.
- Verifikasi: focused 57 tes lulus; full Flutter 148 tes lulus; analyze 0
  error/warning dengan 3 info baseline; diff check bersih. Backend tidak diubah
  dalam perbaikan blocker ini; hasil backend di atas berasal follow-up FIFO.
- Snapshot pending disimpan di provider sesi; verifikasi ini tidak mencakup
  pemulihan setelah proses aplikasi dihentikan atau perangkat restart.
