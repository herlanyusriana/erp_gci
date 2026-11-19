# Task 16: Hapus `issue_screen.dart` + pindahkan test

**Source:** `tasks/audit-fixes/mobile-audit.md` — R3

**What to build:** Layar issue lama yang tidak lagi dipakai di produksi dihapus,
setelah kasus test yang masih bernilai dipindahkan ke layar yang benar-benar
dilihat operator (`GuidedIssueScreen`).

## Latar

`lib/router/app_router.dart:114-119` memetakan `/work-orders/:id/issue` ke
`GuidedIssueScreen`. `issue_screen.dart` (785 baris) diimpor **hanya** oleh
`test/issue_screen_test.dart`, `test/pending_release_test.dart`,
`test/accessibility_test.dart`, dan `test/golden/screens_golden_test.dart`.
Logika `_submit`/`_scan` di dalamnya sudah menyimpang dari alur hidup. Artinya
satu kelas regresi "tercover" oleh test yang menguji layar yang tidak pernah
dibuka operator.

## Acceptance criteria

- [x] Tidak ada lagi file di `lib/` yang mengimpor `issue_screen.dart`
      (diverifikasi `grep -rn "issue_screen.dart" lib/` → kosong).
- [x] Kasus pending-release di `pending_release_test.dart` dipindahkan ke
      `GuidedIssueScreen` (perilaku yang sama: attempt tertunda terlihat, retry
      mengirim payload beku).
- [x] Entri aksesibilitas dan golden yang relevan dipindahkan atau dihapus
      beserta gambarnya; tidak ada golden yang merujuk layar yang tidak ada.
- [x] `test/issue_screen_test.dart` dihapus; `test/scanner_polish_test.dart` dan
      `test/issue_tag_suggestion_test.dart` tetap lulus tanpa perubahan perilaku.
- [x] Jumlah test lulus **tidak turun** dari baseline 214 (test yang dipindah
      dihitung, yang memang duplikat dihapus beserta alasannya dicatat di PR).

## Verification

- [x] Full mobile suite: `cd ../erp_gci_mobile && flutter test`
- [x] Analyze: `cd ../erp_gci_mobile && flutter analyze` (tidak ada unused import)
- [x] Build: `cd ../erp_gci_mobile && flutter build apk --debug`
- [x] Manual check: rute `/work-orders/{id}/issue` tetap membuka
      `GuidedIssueScreen`; alur scan → bundle → release berjalan

## Blocked by

Task 09, 10, 11 — pemindahan test pending-release harus menguji state per WO yang
sudah final.

## Files likely touched

- `lib/features/issue/issue_screen.dart` (hapus)
- `test/issue_screen_test.dart` (hapus setelah dipindah)
- `test/pending_release_test.dart`
- `test/accessibility_test.dart`
- `test/golden/screens_golden_test.dart` (+ `test/golden/goldens/issue_screen*.png`)

## Estimated scope

M — 6 file (2 dihapus)

## Rollback

`git revert` commit ini memulihkan layar dan test-nya. Tidak ada perubahan pada
data atau server.

## Notes

- Urutan wajib: pindahkan → jalankan suite → baru hapus. Jangan sebaliknya.
- Bila ada kasus di layar lama yang **tidak** bisa direproduksi di layar baru
  (mis. edit qty per baris di 685–709), jangan porting buta: catat sebagai
  perilaku yang memang tidak ada di alur hidup dan buang bersama layarnya.
- `issue_screen.dart` masih memuat satu perilaku berbahaya (`updateQty`
  mengubah 0 menjadi 0.0001). Pastikan tidak ikut tersalin. **Catatan saat
  eksekusi:** `IssueNotifier.updateQty` sekarang menjaga `value > 0`, jadi
  perilaku itu tidak ada lagi di jalur hidup dan tidak perlu ditambal.
