# Task 06: APK mengirim idempotency key + guard tap ganda

**Source:** `tasks/audit-fixes/mobile-audit.md` — C3

**What to build:** APK menghasilkan satu key per percobaan laporan, memakainya
ulang saat retry, dan tidak mengirim request kedua saat yang pertama masih
berjalan.

## Acceptance criteria

- [ ] `IssueRepository.reportResultRaw()` menerima `idempotencyKey` dan
      mengirimnya di body.
- [ ] `ProductionSessionScreen` membuat key (uuid v4) saat halaman dibuka,
      memakainya untuk setiap retry, dan **mengganti key hanya setelah sukses**.
- [ ] Key tidak diganti saat gagal jaringan/timeout — ini yang membuat retry
      aman.
- [ ] `_submit` punya guard masuk (`if (_saving) return;`) dan `_saving` diset
      **sebelum** await, sehingga dua tap cepat tidak menghasilkan dua request.
- [ ] `step_entry_dialog` juga mengirim key dan punya flag in-flight (jalur
      `/production-result/:id` memakai endpoint yang sama).
- [ ] Test: submit dua kali dengan key sama (simulasi timeout) → repository
      dipanggil dengan key identik; setelah sukses, key berikutnya berbeda.

## Verification

- [ ] Tests pass: `cd ../erp_gci_mobile && flutter test test/production_result_idempotency_test.dart test/production_session_screen_test.dart`
- [ ] Full mobile suite: `cd ../erp_gci_mobile && flutter test`
- [ ] Analyze: `cd ../erp_gci_mobile && flutter analyze`
- [ ] Build: `cd ../erp_gci_mobile && flutter build apk --debug`
- [ ] Manual check: matikan Wi-Fi setelah tap Save, nyalakan, tap Save lagi —
      server hanya punya satu baris untuk periode itu

## Blocked by

Task 05 — kolom dan validasi key harus ada.

## Files likely touched

- `lib/repositories/issue_repository.dart`
- `lib/features/production_result/production_session_screen.dart`
- `lib/features/production_result/step_entry_dialog.dart`
- `test/production_result_idempotency_test.dart` (baru)

## Estimated scope

S — 4 file

## Rollback

Klien kembali tidak mengirim key; server tetap menerima (kolom nullable) tetapi
proteksi duplikat hilang. Jangan rollback server lebih dulu.

## Notes

- Tiru `IssueNotifier._idempotencyKey` (`lib/providers/issue_provider.dart:161-171`)
  dan `_sendAttempt` (415-429), bukan membuat mekanisme baru.
- Guard harus di level method, bukan hanya `onPressed` di build — tap kedua bisa
  terjadi sebelum frame berikutnya.
