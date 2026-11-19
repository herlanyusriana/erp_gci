# Implementation Plan: Perbaikan Hasil Audit Material Tracker (APK)

## Overview

Rencana eksekusi dari `tasks/audit-fixes/mobile-audit.md`. Fokus: **menghentikan
kerusakan data dan pekerjaan yang berhenti** — delapan temuan Critical pada APK,
ditambah empat temuan web yang sudah disetujui (A1–A3 dan residu gross weight).

Ruang lingkup sengaja tidak mencakup 43 Required + 23 Optional + 25 Nit dari
laporan audit. Yang tidak masuk di sini didaftar di Phase 7 sebagai backlog
terukur, bukan diabaikan.

Bukan pekerjaan yang sama dengan:
- `tasks/plan.md` (Material Tracker Clarity) — **tidak disentuh**.
- `tasks/audit-fixes/plan.md` (perbaikan audit web, Task 01–17) — **tidak disentuh**.

Konvensi repo: satu folder per plan. Plan ini hidup di
`tasks/mobile-audit-fixes/`.

Target task list: file di direktori ini (`task-01.md` … `task-17.md`).

## Keputusan yang sudah diambil

| Pertanyaan | Keputusan |
|---|---|
| Boleh mengubah server `erp_gci`? | **Ya.** C1 butuh kontrak payload, C2 butuh endpoint baru, C3 butuh kolom idempotency. |
| `issue_screen.dart` (785 baris, mati di produksi)? | **Hapus**, test dipindahkan ke `GuidedIssueScreen` (Task 16). |
| A1–A3 dan gross weight? | **Dikerjakan** (A1 menyatu dengan Task 07; A2–A3 di Task 13–14; gross weight di Task 15). Gross weight dihapus dari `Arrival/Form` dan `LocalPo/Form`. |
| Ruang lingkup gelombang | **8 Critical dulu** (Task 01–12), lalu sisa web + pembersihan (Task 13–17). Sisanya backlog Phase 7. |

## Architecture Decisions

- **Progres loading adalah per part, bukan per baris.** `delivery_scans` tidak
  punya `delivery_item_id`; scan terikat ke `part_stock_id`. Jadi "scanned qty
  per baris" memang tidak ada di domain — yang salah adalah APK memperlakukannya
  per baris lalu mengunci baris kedua. Perbaikan: server mengirim blok
  `part_progress[]` yang eksplisit, APK memakai pool per part. **Tidak ada
  perubahan skema** untuk C1.
- **`allTagsScanned()` tidak diubah.** Ia sudah benar (per part) dan menjadi
  penjaga `moveToPreparation`. Yang diperbaiki hanya cara APK membaca progres.
- **Field lama dipertahankan.** `scanned_tags`/`total_tags` juga dipakai
  `Outgoing/Delivery/Show.vue`; menamainya ulang = breaking change tanpa
  manfaat. Tambah `covered_parts`/`total_parts` sebagai nama jujur, biarkan yang
  lama sebagai alias.
- **Endpoint OQC baru memanggil service yang sudah ada.** `passQc()`/`rejectQc()`
  di `DeliveryService` sudah dipakai web; API hanya perlu membungkusnya dengan
  otorisasi yang sama (`delivery.oqc`). Tidak ada logika kedua.
- **Idempotency hasil produksi meniru alur release.** Kolom
  `production_results.idempotency_key` nullable + unique, pola `first()` di
  service. Pola ini sudah terbukti di `material_issues`.
- **Plafon qty ditegakkan di server, APK hanya memberi peringatan lebih awal.**
  APK boleh salah hitung; server tidak boleh menerima angka yang mustahil.
- **Pengaman back memakai `PopScope`, bukan penyimpanan ke disk.** Persistensi
  lintas process-death butuh dependency baru (`shared_preferences`) yang belum
  ada di `pubspec.yaml` — butuh persetujuan terpisah (lihat Open Questions).
- **Hapus layar mati setelah test dipindahkan, bukan sebaliknya.** Urutan ini
  menjaga cakupan test tidak pernah turun ke nol.

## Phases

### Phase 1: Kontrak loading (C1)

- [x] Task 01: Progres loading per part di payload API
- [x] Task 02: APK memakai pool per part

**Checkpoint 1:** pengiriman dengan satu FG di dua sequence tidak lagi mengunci
operator.

### Phase 2: OQC (C2)

- [x] Task 03: Endpoint pass/reject OQC di API
- [x] Task 04: APK menyelesaikan OQC

**Checkpoint 1 (setelah Task 03–04):** dokumen bisa berpindah dari `picked` ke
`qc_passed` tanpa membuka web.

### Phase 3: Idempotensi & validasi hasil produksi (C3, C4)

- [x] Task 05: Idempotensi `production_results` di server
- [ ] Task 06: APK mengirim idempotency key + guard tap ganda
- [ ] Task 07: Validasi angka hasil produksi (plafon + hasil reject saja)
- [ ] Task 08: APK memperingatkan qty melebihi sisa

**Checkpoint 2:** satu periode tidak bisa dilaporkan dua kali; angka mustahil
ditolak; reject saja diterima.

### Phase 4: Kehilangan data operator (C5–C8)

- [ ] Task 09: Pengaman back + scan tidak hilang saat layar dimuat ulang
- [ ] Task 10: "Sisa menyusul" tidak membuang scan
- [ ] Task 11: State issue per Work Order
- [ ] Task 12: Daftar penerimaan memuat saat dibuka

**Checkpoint 3:** tidak ada lagi jalur di mana kerja fisik operator hilang tanpa
konfirmasi.

### Phase 5: Sisa temuan web

- [ ] Task 13: A2 — selaraskan permission `resolveTag`
- [ ] Task 14: A3 — qty tersedia di `resolveTag`
- [ ] Task 15: Hapus input gross weight dari Arrival & Local PO

**Checkpoint 4 (paruh pertama — Task 13–15):** temuan web yang terbuka sejak
audit sebelumnya tertutup. (A1 sudah dikerjakan bersama Task 07 karena menyentuh
blok validasi yang sama.)

### Phase 6: Pembersihan & i18n minimum

- [ ] Task 16: Hapus `issue_screen.dart` + pindahkan test
- [ ] Task 17: Key `scanTag`/`saving` + guard paritas katalog

**Checkpoint 4 (final — Task 16–17):** suite web & APK hijau, tidak ada key
mentah di layar.

## Parallelization

| Task | Kategori | Catatan |
|---|---|---|
| 01 | Must be sequential | Menentukan bentuk payload yang dibaca Task 02 |
| 02 | Must be sequential | Bergantung pada 01; menyentuh layar loading |
| 03 | Safe to parallelize | File berbeda dari 01–02 (`routes/api.php`, controller) |
| 04 | Must be sequential | Butuh endpoint dari 03 |
| 05 | Safe to parallelize | Migration + service sendiri |
| 06 | Must be sequential | Butuh kontrak kolom dari 05 |
| 07 | Needs coordination | Menyentuh `ProductionResultService` yang sama dengan 05 |
| 08 | Must be sequential | Butuh field sisa dari 07 |
| 09, 10, 11 | Must be sequential | Satu file: `guided_issue_screen.dart` / `issue_provider.dart` |
| 12 | Safe to parallelize | Provider + layar receipt, terpisah |
| 13, 14 | Safe to parallelize | Controller API yang sama dengan 07; koordinasikan urutan commit |
| 15 | Safe to parallelize | Web saja |
| 16 | Must be sequential | Menyentuh test yang dipakai 09–11 |
| 17 | Safe to parallelize | Katalog i18n + test baru |

Empat jalur yang tidak saling bertabrakan:
- **A — loading & OQC:** Task 01–04
- **B — hasil produksi:** Task 05–08
- **C — issue & receipt APK:** Task 09–12, lalu 16–17
- **D — web:** Task 13–15

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Pool per part mengubah arti baris rencana | High | `allTagsScanned()`/`moveToPreparation` tidak diubah; test loading yang ada wajib tetap lulus; baris hanya dipakai untuk memilih niat operator, bukan untuk menutup qty |
| Endpoint OQC baru melewati aturan yang dijaga web | High | API memanggil `DeliveryService::passQc/rejectQc` yang sama, bukan query baru; test permission untuk role `qc` dan role lain |
| Idempotency hanya di APK, server tetap menerima duplikat | High | Kolom unique di DB + lookup `first()` di service; test retry dua kali → satu baris |
| Plafon memakai `target_qty` yang artinya qty WO, bukan per periode | Med | Server menegakkan plafon **per laporan** dan plafon kumulatif `qty_good` yang sudah ada (tidak dilonggarkan); APK hanya peringatan. Plafon kumulatif `qty_reject` belum diputuskan — Open Question 1 |
| Menghapus `issue_screen.dart` menurunkan cakupan test | Med | Port test + golden + a11y ke `GuidedIssueScreen` lebih dulu (Task 16), verifikasi suite tidak turun dari 214 |
| Menghapus input gross weight memutus validasi nett > gross | Low | Hapus juga aturan di `Arrival/Form:308-309`; kolom DB tetap nullable sehingga data lama utuh |
| APK butuh dependency baru untuk draft lintas restart | Low | Tidak dilakukan di plan ini; dicatat sebagai Open Question |

## Open Questions

1. **Plafon `qty_reject` per periode.** `result-context` mengirim `target_qty` =
   qty WO (bukan qty periode), dan `report()` baru menegakkan plafon **per
   laporan** (`qty_good + qty_reject ≤ qty WO`) di samping plafon kumulatif
   `qty_good` yang sudah ada. Apakah `qty_reject` perlu plafon kumulatif
   (mis. reject ≤ sisa WO) belum diputuskan — butuh keputusan operasi, jangan
   dikarang.
2. **Persistensi scan lintas process-death.** Task 09 hanya menjaga state di
   memori + konfirmasi back. Bila operator perlu memulihkan scan setelah APK
   dibunuh Android, perlu `shared_preferences`/`hive` — dependency baru, butuh
   persetujuan.
3. **Label progres di web.** `Delivery/Show.vue` menampilkan "scanned / total"
   yang sebenarnya menghitung **part**. Task 01 menambah field jujur; apakah
   label web ikut diganti ke "part" (kecil, tapi menyentuh teks i18n)?

## Task Index

| # | Task | Size | Blocked by | File |
|---|---|---|---|---|
| 01 | Progres loading per part di payload API | M | — | `task-01.md` |
| 02 | APK memakai pool per part | M | 01 | `task-02.md` |
| 03 | Endpoint pass/reject OQC di API | S | — | `task-03.md` |
| 04 | APK menyelesaikan OQC | S | 03 | `task-04.md` |
| 05 | Idempotensi `production_results` di server | M | — | `task-05.md` |
| 06 | APK mengirim idempotency key + guard tap | S | 05 | `task-06.md` |
| 07 | Validasi angka hasil produksi (plafon + reject saja) | M | 05 | `task-07.md` |
| 08 | APK memperingatkan qty melebihi sisa | S | 07 | `task-08.md` |
| 09 | Pengaman back + scan tidak hilang | S | — | `task-09.md` |
| 10 | "Sisa menyusul" tidak membuang scan | S | 09 | `task-10.md` |
| 11 | State issue per Work Order | M | 09, 10 | `task-11.md` |
| 12 | Daftar penerimaan memuat saat dibuka | S | — | `task-12.md` |
| 13 | A2 — permission `resolveTag` | XS | — | `task-13.md` |
| 14 | A3 — qty tersedia di `resolveTag` | M | — | `task-14.md` |
| 15 | Hapus input gross weight | XS | — | `task-15.md` |
| 16 | Hapus `issue_screen.dart` + pindahkan test | M | 09, 10, 11 | `task-16.md` |
| 17 | Key hilang + guard paritas katalog | S | — | `task-17.md` |

## Phase 7: Backlog (belum ditask, sengaja ditunda)

Sisa temuan `tasks/audit-fixes/mobile-audit.md` yang **tidak** dikerjakan di plan
ini. Dikelompokkan agar bisa dijadikan plan berikutnya tanpa membaca ulang
laporan.

| Kelompok | Isi | Perkiraan |
|---|---|---|
| i18n penuh | R12, R13, R28, R40 — 56 string hardcoded di 15 file (warehouse_home 17 situs, hub 6, guided issue 11, bundle 4, history 4) | 1 plan, 3–4 task |
| Offline | R10, R41 — seed `Connectivity().checkConnectivity()`, banner di 14 layar, nonaktifkan tombol kirim | 1 plan, 2 task |
| Aksesibilitas & tipografi | R22, R24, R25 + Nit 10pt/11pt — lantai 12pt untuk teks keputusan, target 48dp, input kode manual di scanner | 1 plan, 2–3 task |
| Test coverage | R42 — 12 layar tanpa rujukan test | 1 plan, 3 task |
| Otomasi i18n | R39 — guard paritas (Task 17 sudah mengerjakan versi minimal) | termasuk Task 17 |
| Identitas part | R19, R26, R31, R35, R36, R37 + Nit history — Model di loading/receipt/history, qty prefill, mounted guard | 1 plan, 4 task |
| Duplikasi & kerapian | Optional 1–23 — `_ErrorView` ×4, provider ganda, `scanCooldown` mati, tab bar tanpa hub | 1 plan, 2–3 task |
| Permission di APK | R43 — `roles`/`permissions` di `GET /api/user`, menu dari permission | butuh keputusan produk |
