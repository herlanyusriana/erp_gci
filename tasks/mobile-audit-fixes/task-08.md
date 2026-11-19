# Task 08: APK memperingatkan qty yang melebihi sisa

**Source:** `tasks/audit-fixes/mobile-audit.md` — C4

**What to build:** Layar laporan produksi menampilkan sisa qty part dan meminta
konfirmasi sebelum mengirim angka yang melebihi sisa, sehingga salah ketik satu
nol tertangkap di HP — bukan setelah stok terpotong.

## Acceptance criteria

- [ ] `ProductionSessionPart` membaca `targetQty`, `producedQty`,
      `remainingQty` dari payload Task 07.
- [ ] Layar menampilkan sisa (mis. "Sisa 500 / 1.000") di dekat input qty.
- [ ] Bila `qty_good + qty_reject > remainingQty`, tekan Save menampilkan
      konfirmasi berisi angka yang diketik dan sisa sebenarnya; batal =
      tidak ada request.
- [ ] Qty yang bukan angka finite atau negatif ditolak sebelum request (termasuk
      `qty_reject`), bukan di-`?? 0` diam-diam.
- [ ] Bila server tidak mengirim field baru (APK lama vs server lama), layar
      tetap berfungsi tanpa peringatan — jangan crash.
- [ ] Test: input melebihi sisa → dialog muncul dan repository tidak dipanggil
      saat dibatalkan; dalam batas → langsung terkirim.

## Verification

- [ ] Tests pass: `cd ../erp_gci_mobile && flutter test test/production_result_qty_guard_test.dart test/production_session_screen_test.dart`
- [ ] Full mobile suite: `cd ../erp_gci_mobile && flutter test`
- [ ] Analyze: `cd ../erp_gci_mobile && flutter analyze`
- [ ] Build: `cd ../erp_gci_mobile && flutter build apk --debug`
- [ ] Manual check: isi Good 10.000 pada part dengan sisa 500 → konfirmasi
      muncul dan menyebut 500

## Blocked by

Task 07 — field sisa dan plafon server.

## Files likely touched

- `lib/models/production_session.dart`
- `lib/features/production_result/production_session_screen.dart`
- `test/production_result_qty_guard_test.dart` (baru)

## Estimated scope

S — 3 file

## Rollback

Perubahan klien murni. Tanpa field server, peringatan tidak tampil (perilaku
lama), pengiriman tetap jalan.

## Notes

- Konfirmasi, bukan blokir: sisa qty di server bisa berubah karena laporan
  operator lain. Server tetap yang memutuskan.
