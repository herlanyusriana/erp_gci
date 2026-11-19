# Task 09: Pengaman back + scan tidak hilang saat layar dimuat ulang

**Source:** `tasks/audit-fixes/mobile-audit.md` — C5

**What to build:** Menekan back saat masih ada tag yang belum dirilis tidak
langsung membuang pekerjaan; dan membuka kembali Work Order yang sama tidak
mengosongkan daftar scan yang sudah ada.

## Latar

Baris scan hanya hidup di `issueProvider`. `load()` selalu menimpa state dengan
`IssueState(loading: true)`, dan tidak ada `PopScope` di seluruh aplikasi — back
default langsung pop. Operator yang sudah menarik 20 tag kehilangan semuanya
tanpa satu pun pemberitahuan.

## Acceptance criteria

- [x] `GuidedIssueScreen` dibungkus `PopScope`; `canPop` hanya `true` bila
      `lines.isEmpty && pendingRelease == null`.
- [x] Bila tidak boleh pop, dialog menampilkan jumlah tag yang belum dirilis
      dengan pilihan **Buang & keluar** / **Kembali ke layar**.
- [x] `load(workOrderId)` dengan WO **sama** dan `lines` tidak kosong hanya
      menyegarkan konteks (kebutuhan/stok) dan **mempertahankan** `lines`,
      `_materialIndex`, dan qty yang sudah diisi.
- [x] `load()` dengan WO berbeda tetap mengosongkan state (ditegakkan penuh di
      Task 11).
- [x] Test: isi 2 baris scan → tekan back → dialog muncul dan provider tidak
      di-reset; tekan "Buang" → keluar dan state bersih.
- [x] Test: buka layar, isi scan, panggil `load` WO yang sama → `lines` tetap 2.

## Verification

- [x] Tests pass: `cd ../erp_gci_mobile && flutter test test/guided_issue_draft_test.dart`
- [x] Full mobile suite: `cd ../erp_gci_mobile && flutter test`
- [x] Analyze: `cd ../erp_gci_mobile && flutter analyze`
- [x] Build: `cd ../erp_gci_mobile && flutter build apk --debug`
- [x] Manual check: scan 2 tag → back → muncul dialog; pilih kembali, daftar
      masih 2 baris

## Blocked by

None — can start immediately.

## Files likely touched

- `lib/features/issue/guided_issue_screen.dart`
- `lib/providers/issue_provider.dart`
- `test/guided_issue_draft_test.dart` (baru)

## Estimated scope

S — 3 file

## Rollback

Hapus `PopScope` dan kembalikan `load()` ke perilaku menimpa state. Tidak ada
dampak data di server.

## Notes

- **Tidak** menyimpan scan ke disk. Pemulihan setelah proses Android dibunuh
  butuh dependency baru dan dicatat sebagai Open Question 2.
- Dialog memakai `showDialog<bool>` yang sudah dipakai layar ini; jangan
  menambah pola konfirmasi baru.
