# Task 11: State issue per Work Order

**Source:** `tasks/audit-fixes/mobile-audit.md` — C7

**What to build:** Percobaan release yang belum terkonfirmasi tidak lagi merebut
layar Work Order lain; operator selalu melihat material WO yang benar dan tombol
retry hanya muncul untuk WO yang memang punya percobaan tertunda.

## Latar

`load()` diawali `if (state.pendingRelease != null) return;`. Akibatnya membuka
WO-B setelah release WO-A gagal **tidak memuat apa pun**: layar menampilkan
material WO-A, dan `_stageButton` di semua stage hanya mengembalikan tombol
"retry previous release" yang memposting attempt WO-A. `reset()` juga diblokir,
jadi satu-satunya jalan keluar adalah mematikan paksa APK.

## Acceptance criteria

- [ ] `IssueState` menyimpan `workOrderId`.
- [ ] `load(id)` **selalu** memuat konteks untuk `id` bila `id != state.workOrderId`;
      baris scan WO lama dibersihkan (dengan konfirmasi Task 09 bila perlu).
- [ ] `pendingRelease` menyimpan `workOrderId` asalnya.
- [ ] `_stageButton` hanya menampilkan "Retry release" bila
      `pendingRelease.workOrderId == state.workOrderId`.
- [ ] Bila ada attempt tertunda milik WO lain, layar menampilkan kartu terpisah
      ("Release WO-A belum terkonfirmasi") dengan aksi **Retry** dan **Buang** —
      bukan tombol utama.
- [ ] `reset()` boleh membersihkan `pendingRelease` hanya lewat aksi eksplisit
      pengguna, bukan otomatis saat pindah layar.
- [ ] Test: release WO-A gagal → `load(WO-B)` → konteks WO-B tampil, tombol
      retry tidak muncul, kartu menyebut WO-A; Retry mengirim payload WO-A.

## Verification

- [ ] Tests pass: `cd ../erp_gci_mobile && flutter test test/issue_state_per_wo_test.dart test/issue_notifier_test.dart test/pending_release_test.dart`
- [ ] Full mobile suite: `cd ../erp_gci_mobile && flutter test`
- [ ] Analyze: `cd ../erp_gci_mobile && flutter analyze`
- [ ] Build: `cd ../erp_gci_mobile && flutter build apk --debug`
- [ ] Manual check: matikan Wi-Fi → release WO-A → buka WO-B → material WO-B
      tampil dan tidak ada tombol yang bisa merilis WO-A

## Blocked by

Task 09 (manajemen baris scan saat load) dan Task 10 (jalur keluar yang aman).
Ketiganya menyentuh layar/provider yang sama.

## Files likely touched

- `lib/providers/issue_provider.dart`
- `lib/features/issue/guided_issue_screen.dart`
- `test/issue_state_per_wo_test.dart` (baru)

## Estimated scope

M — 3 file

## Rollback

Kembalikan guard `load()` dan tombol stage. Attempt tertunda tetap aman di
memori seperti sebelumnya.

## Notes

- Alternatif `NotifierProvider.family` per WO **tidak** dipilih: satu operator
  menangani satu WO pada satu waktu, dan attempt tertunda harus tetap terlihat
  lintas WO. Menyimpan `workOrderId` di state lebih kecil dan menyelesaikan
  masalahnya tanpa memecah provider.
- `pending_release_test.dart` saat ini menguji `issue_screen.dart` (layar mati).
  Setelah Task 16, test itu menunjuk `GuidedIssueScreen`; jaga perilakunya tetap
  sama.
