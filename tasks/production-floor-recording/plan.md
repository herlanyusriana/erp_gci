# Implementation Plan: Production Floor Recording

## Overview

Mencatat hasil produksi di lantai per **periode jam** dalam satu sesi mesin, dengan
identitas WIP mengikuti **nomor WO** (bukan barcode per unit), dan label Good/NG
yang dicetak dari **Web oleh petugas gudang** lalu diantar ke mesin.

PRD: `docs/prd/production-floor-recording.md`

Capability map (dari PRD):

| Module id | Responsibility | Depends on |
|---|---|---|
| `wip-identity` | Identitas WIP per WO, konsumsi FIFO + konfirmasi ambigu | - |
| `production-session` | Sesi mesin: pilih mesin, part, shift, dropdown periode | `wip-identity` |
| `period-reporting` | Simpan Good/NG per periode, tandai laporan susulan | `production-session` |
| `wip-label` | Antrean cetak di Web, label Good/NG, status terkirim | `period-reporting` |

Build order: `wip-identity` → `production-session` → `period-reporting` → `wip-label`

## Architecture Decisions

- **Identitas WIP = WO.** Tidak ada tabel batch baru. Beberapa periode dalam WO
  yang sama menambah `qty` pada identitas WIP yang sama (kunci: WO + part hasil).
  Konsekuensi yang diterima: WIP tidak bisa dibedakan per jam.
- **Periode disimpan eksplisit** pada baris hasil produksi (`period_start`,
  `period_end`, `shift`, `is_backfilled`), menggantikan ketergantungan pada
  `result_date` saja.
- **Status label punya 4 keadaan** (`pending` → `printed` → `delivered` → `none`)
  dan **tidak pernah memblokir** penyimpanan hasil produksi.
- **Cetak label hanya di Web.** APK tidak punya fitur cetak; printer ada di gudang
  dan gudang yang mengantar.
- **Periode dipilih dari dropdown**, bukan mengikuti jam berjalan, supaya laporan
  susulan bisa diisi. Dropdown tidak menawarkan periode yang belum terjadi.
- **FIFO otomatis dengan pengaman**: konfirmasi WO hanya muncul bila ada lebih dari
  satu WO yang menyediakan part WIP yang sama di mesin tersebut.

## Task List

### Phase 1: `wip-identity`

- [ ] **Task 1: Identitas WIP per WO + saldo per (WO, part)**
  - Acceptance: WIP yang lahir dari laporan periode dapat dibaca sebagai saldo per
    WO + part; beberapa periode dalam WO yang sama menambah qty yang sama, bukan
    membuat baris baru.
  - Verify: feature test — 3 periode dalam 1 WO menghasilkan 1 identitas dengan
    qty gabungan.
  - Files: service WIP, model terkait, test baru.
  - Scope: Medium.

- [ ] **Task 2: Konsumsi WIP FIFO + kandidat ambigu**
  - Acceptance: saat hanya satu WO kandidat, FIFO memotong tanpa konfirmasi; saat
    lebih dari satu WO punya part WIP sama, endpoint mengembalikan daftar kandidat
    agar APK bisa bertanya.
  - Verify: feature test dua skenario (satu kandidat, banyak kandidat).
  - Files: service konsumsi, endpoint kandidat, test.
  - Scope: Medium.

### Checkpoint: `wip-identity`

- [ ] Test hijau, tidak ada regresi pada `MachineMaterialConsumptionTest`.

### Phase 2: `production-session`

- [ ] **Task 3: Konteks sesi mesin untuk APK**
  - Acceptance: satu endpoint mengembalikan mesin, part hasil dari Production Plan,
    shift berjalan, dan daftar periode yang tersedia untuk shift itu.
  - Verify: feature test — part terisi dari plan; daftar periode tidak memuat
    periode yang belum terjadi.
  - Files: controller API, service periode, test.
  - Scope: Medium.

- [ ] **Task 4: Daftar periode per shift (dropdown)** — **BLOCKED**
  - Blocker: **Open Question #2** — jam mulai shift 2 dan 3 belum ditetapkan.
  - Acceptance: dropdown menampilkan periode dari awal shift sampai periode
    berjalan, tidak melebihi jam sekarang.
  - Verify: unit test fungsi pembentuk periode dengan batas shift final.
  - Files: service periode, test.
  - Scope: Small.

### Checkpoint: `production-session`

- [ ] Sesi mesin dapat dibuka di APK dengan part dan periode terisi.

### Phase 3: `period-reporting`

- [ ] **Task 5: Simpan laporan Good/NG per periode**
  - Acceptance: laporan menyimpan Good, NG, shift, `period_start`, `period_end`;
    qty divalidasi di server; laporan ditolak bila periode belum terjadi.
  - Verify: feature test validasi + penyimpanan.
  - Files: migration kolom periode, controller API, test.
  - Scope: Medium.

- [ ] **Task 6: Penanda laporan susulan**
  - Acceptance: laporan yang diisi setelah periodenya lewat tersimpan dengan
    `is_backfilled = true`; laporan tepat waktu bernilai `false`.
  - Verify: feature test dua skenario waktu.
  - Files: service periode, test.
  - Scope: Small.

- [ ] **Task 7: Batas waktu laporan susulan** — **BLOCKED**
  - Blocker: **Open Question #5** — bebas, atau dibatasi selama shift berjalan.
  - Acceptance: ditentukan setelah Open Question #5 dijawab.
  - Scope: Small.

- [ ] **Task 8: Layar input periode di APK**
  - Acceptance: operator memilih shift, memilih periode dari dropdown, mengisi
    Good/NG, menyimpan; laporan susulan terlihat ditandai.
  - Verify: `flutter test` — widget test pemilihan periode + penyimpanan.
  - Files: layar production result, provider, test.
  - Scope: Medium.

### Checkpoint: `period-reporting`

- [ ] Laporan periode tersimpan dari APK; laporan susulan ditandai.

### Phase 4: `wip-label`

- [ ] **Task 9: Antrean cetak di Web**
  - Acceptance: halaman Web menampilkan laporan periode dengan `label_status =
    pending`, dapat dicetak per jenis (Good/NG), dan ditandai `printed`.
  - Verify: feature test daftar antrean + aksi cetak mengubah status.
  - Files: controller Web, halaman Vue, test.
  - Scope: Medium.

- [ ] **Task 10: Template label Good dan NG**
  - Acceptance: label memuat WO, part, qty, jam periode, shift, jenis, dan QR
    identitas; Good dan NG tercetak terpisah.
  - Verify: feature test isi HTML label; cek manual hasil cetak.
  - Files: Blade label, route cetak, test.
  - Scope: Small.

- [ ] **Task 11: Tandai label terkirim**
  - Acceptance: petugas gudang dapat menandai `delivered`; status tersimpan.
  - Verify: feature test transisi status.
  - Files: controller, halaman Vue, test.
  - Scope: Small.

- [ ] **Task 12: Peringatan "label belum diantar" di APK**
  - Acceptance: WIP dengan status belum `delivered` tampil dengan penanda visual,
    dan **tombol lanjut tidak dinonaktifkan**.
  - Verify: `flutter test` — penanda muncul, tombol tetap aktif.
  - Files: layar produksi APK, test.
  - Scope: Small.

### Checkpoint: Selesai

- [ ] Semua Success Criteria di PRD terpenuhi.
- [ ] `php artisan test` dan `flutter test` hijau.
- [ ] `vendor/bin/pint` dan `flutter analyze` bersih.

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Periode dipilih manual → operator lupa mengisi satu periode | Tinggi | Alarm 5 menit tetap ada; deteksi "periode kosong" diusulkan sebagai PRD terpisah |
| Batch = WO → WIP tidak bisa dibedakan per jam | Sedang | Diterima sadar; jejak waktu tetap ada di `production_results` |
| FIFO memotong WIP dari WO yang salah | Tinggi | Konfirmasi WO saat ada lebih dari satu kandidat (Task 2) |
| Label telat diantar menghambat produksi | Sedang | WIP tetap tersedia; hanya ditandai, tidak diblokir (Task 12) |
| Migrasi kolom periode pada tabel yang sudah berisi data | Tinggi | Kolom nullable + backfill dari `result_date`; minta persetujuan sebelum migrasi |

## Open Questions

1. Merek/model printer gudang — menentukan cara cetak (Task 9/10).
2. Jam mulai shift 2 dan 3 — memblokir Task 4.
3. Periode yang melewati batas shift — mempengaruhi Task 4/5.
4. Retensi label NG — mempengaruhi Task 10.
5. Batas waktu laporan susulan — memblokir Task 7.
