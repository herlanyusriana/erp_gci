# Task 02: APK memakai pool per part

**Source:** `tasks/audit-fixes/mobile-audit.md` — C1

**What to build:** Layar loading menghitung sisa per **part**, bukan per baris,
sehingga baris kedua dengan FG yang sama tidak ikut terkunci saat baris pertama
dipindai.

## Latar

Hari ini `LoadingItem.remaining = qty − scannedQty`, sementara `scannedQty`
adalah total seluruh part. Begitu satu tag masuk, semua baris part itu dianggap
selesai: chip hijau, tombol scan per baris nonaktif, dan tombol FG utama
nonaktif karena `allCovered`. Progres tetap di bawah total sehingga dokumen
tidak pernah bisa `loading_complete`.

## Acceptance criteria

- [x] `LoadingContext` menyimpan `partProgress` dari `data.part_progress` dan
      menyediakan `remainingForPart(int partId)`.
- [x] `LoadingItem.remaining` tidak lagi dipakai untuk mengunci baris; baris
      hanya dipakai sebagai niat operator (seq/customer mana yang sedang diisi).
- [x] Tombol scan per baris aktif selama `remainingForPart(partId) > 0`, dan
      tombol FG utama mengikuti kondisi yang sama.
- [x] `allCovered` dihitung dari `part_progress` (semua `covered == true`),
      bukan dari `items.every((i) => i.remaining <= 0)`.
- [x] Tampilan progres memakai `covered_parts` / `total_parts` (angka yang sama
      dengan server), bukan turunan dari baris.
- [x] Test: satu FG di dua baris — setelah scan sebagian, kedua baris tetap
      dapat dipindai dan tombol utama tetap aktif; setelah qty penuh, semuanya
      nonaktif dan progres mencapai total.

## Verification

- [x] Tests pass: `flutter test test/loading_context_test.dart test/loading_part_progress_test.dart` — 11 passed
- [x] Full mobile suite: `flutter test` — 219 passed (dari 214)
- [x] Analyze: `flutter analyze` — 5 info lama, 0 error/warning
- [x] Build: `flutter build apk --debug` — ok
- [x] Manual check: dokumen dengan part sama di seq 1 dan 2 — pindai satu tag,
      baris kedua tidak berubah hijau

## Blocked by

Task 01 — butuh `part_progress` dan `covered_parts`/`total_parts`.

## Files likely touched

- `lib/models/loading_context.dart`
- `lib/features/loading/loading_scan_screen.dart`
- `test/loading_context_test.dart`
- `test/loading_part_progress_test.dart` (baru)

## Estimated scope

M — 3 file

## Rollback

Perubahan hanya di klien. Mengembalikan file ke versi sebelumnya memulihkan
perilaku lama; server tetap kompatibel karena field lama dipertahankan.

## Notes

- Bila `part_progress` tidak ada (server lama), jatuh ke perilaku lama lewat
  `items[].scanned_qty` agar APK lama-tetap-jalan saat server belum di-deploy.
- Jangan menyentuh `_resolveRow`/`_pickRow` di task ini; pemilihan baris yang
  salah adalah temuan R18 di backlog.
