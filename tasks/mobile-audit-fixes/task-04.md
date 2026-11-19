# Task 04: APK menyelesaikan OQC

**Source:** `tasks/audit-fixes/mobile-audit.md` — C2

**What to build:** Setelah semua troli ditandai, layar loading menawarkan aksi
**Selesaikan OQC** / **Tolak OQC**, sehingga operator bisa menutup dokumen
sampai siap dikirim.

## Acceptance criteria

- [ ] `DeliveryRepository` punya `passQc(deliveryId, {notes})` dan
      `rejectQc(deliveryId, {notes})` yang memanggil endpoint Task 03.
- [ ] Aksi muncul di blok `if (moved)` setelah semua troli `checked`;
      tersembunyi/nonaktif sebelum itu.
- [ ] Reject mewajibkan catatan (dialog input), pass boleh tanpa catatan.
- [ ] Tombol per-troli OK/NG dinonaktifkan bila `qcStatus != 'pending'`.
- [ ] Setelah sukses, konteks dimuat ulang dan status dokumen terlihat berubah
      (`picked` → `qc_passed`).
- [ ] Test widget: semua troli checked → tombol selesai tampil; tekan pass →
      repository dipanggil sekali; reject tanpa catatan tidak mengirim request.

## Verification

- [ ] Tests pass: `cd ../erp_gci_mobile && flutter test test/loading_oqc_completion_test.dart`
- [ ] Full mobile suite: `cd ../erp_gci_mobile && flutter test`
- [ ] Analyze: `cd ../erp_gci_mobile && flutter analyze`
- [ ] Build: `cd ../erp_gci_mobile && flutter build apk --debug`
- [ ] Manual check: dokumen `picked` dengan semua troli OK → tekan Selesaikan
      OQC → status berubah tanpa membuka web

## Blocked by

Task 03 — endpoint harus ada lebih dulu.

## Files likely touched

- `lib/repositories/delivery_repository.dart`
- `lib/features/loading/loading_scan_screen.dart`
- `test/loading_oqc_completion_test.dart` (baru)

## Estimated scope

S — 3 file

## Rollback

Hapus dua method repository dan blok UI-nya. Dokumen kembali hanya bisa
diselesaikan dari web, seperti sebelum task ini.

## Notes

- Pesan error memakai `e.message` dari server agar alasan 422 (mis. "OQC hanya
  bisa dilakukan setelah barang berada di area pengiriman") sampai apa adanya.
- Lokalisasi teks tombol baru masuk backlog i18n penuh (Phase 7); untuk sementara
  pakai key yang sudah ada bila memungkinkan, dan catat key baru di sana.
