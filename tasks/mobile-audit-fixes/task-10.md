# Task 10: "Sisa menyusul" tidak membuang scan

**Source:** `tasks/audit-fixes/mobile-audit.md` — C6

**What to build:** Tombol "Selesai (Sisa Menyusul)" berhenti menjadi jalan
keluar yang diam-diam menghapus scan; bila masih ada tag yang belum dirilis,
operator diberi tahu dan ditawari mengirimnya lebih dulu.

## Latar

`_finishDeferRemaining` hanya memanggil `_refreshLists()` lalu
`context.go('/warehouse-home')`. Tombol ini muncul justru ketika
`_hasAnyIssued` — kondisi tipikal operator yang sudah merilis item 1 dan masih
memegang scan item 2–3. Baris itu dibuang pada `load()` berikutnya, bon menjadi
kurang, dan produksi melaporkan material hilang padahal fisiknya ada di troli.

## Acceptance criteria

- [x] Bila `state.lines.isNotEmpty`, menekan tombol menampilkan konfirmasi yang
      menyebut **jumlah tag** yang belum dirilis dan tiga pilihan: kirim
      sekarang / buang & selesai / batal.
- [x] "Kirim sekarang" memanggil jalur submit yang sudah ada (Task 09 menjamin
      state tidak terhapus), bukan implementasi baru.
- [x] "Buang & selesai" adalah satu-satunya jalur yang menghapus baris, dan itu
      tertulis jelas di dialog.
- [x] Bila `lines.isEmpty`, perilaku tetap: langsung kembali ke warehouse home.
- [x] Test: 2 baris belum dirilis → tombol memunculkan dialog; batal → tetap di
      layar; buang → provider bersih dan navigasi terjadi.

## Verification

- [x] Tests pass: `cd ../erp_gci_mobile && flutter test test/guided_issue_defer_test.dart`
- [x] Full mobile suite: `cd ../erp_gci_mobile && flutter test`
- [x] Analyze: `cd ../erp_gci_mobile && flutter analyze`
- [x] Build: `cd ../erp_gci_mobile && flutter build apk --debug`
- [x] Manual check: rilis 1 item, sisakan 2 tag, tekan "Sisa Menyusul" → dialog
      menyebut 2 tag

## Blocked by

Task 09 — jaminan bahwa state tidak terhapus saat kembali ke layar.

## Files likely touched

- `lib/features/issue/guided_issue_screen.dart`
- `test/guided_issue_defer_test.dart` (baru)

## Estimated scope

S — 2 file

## Rollback

Kembalikan handler ke versi lama (langsung `context.go`). Tidak ada perubahan
server.

## Notes

- Label tombol tidak diubah di task ini (i18n masuk backlog). Yang berubah hanya
  perilaku di baliknya — dan perilaku lama melanggar janji labelnya.
