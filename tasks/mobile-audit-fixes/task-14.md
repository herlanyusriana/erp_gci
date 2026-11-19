# Task 14: A3 — qty tersedia di `resolveTag`

**Source:** `tasks/audit-fixes/mobile-audit.md` — A3

**What to build:** Tanggapan `resolveTag` menyatakan **qty yang benar-benar bisa
diambil** (stok dikurangi booking), dan APK memakai angka itu sebagai batas
pada layar bundle.

## Latar

Server sudah mengirim `qty` (stok fisik) dan `booked`, tetapi APK hanya membaca
`qty`. Stok yang sudah dibooking WO lain tetap dianggap tersedia, sehingga
operator bisa menyetujui qty yang kemudian ditolak saat release — setelah ia
memilih bundle dan menekan konfirmasi.

## Acceptance criteria

- [ ] `resolveTag` menambahkan `available_qty = max(0, qty − booked)` pada
      `data`, di samping `qty` dan `booked` yang tetap ada.
- [ ] Kandidat 409 (`candidates[]`) juga membawa `available_qty`.
- [ ] `ResolvedTag` di APK memuat `availableQty` (fallback ke `qty` bila field
      tidak ada).
- [ ] Layar bundle memakai `availableQty` sebagai batas atas qty manual dan
      menampilkan angka tersedia bila lebih kecil dari stok.
- [ ] Test server: stok 100 dengan booking 30 → `available_qty` 70.
- [ ] Test APK: tag dengan available 70, input manual 80 → ditolak sebelum
      request dengan pesan yang menyebut 70.

## Verification

- [ ] Tests pass: `vendor/bin/pest tests/Feature/Api/MaterialIssueApiTest.php`
- [ ] Tests pass: `cd ../erp_gci_mobile && flutter test test/issue_bundle_screen_test.dart`
- [ ] Full mobile suite: `cd ../erp_gci_mobile && flutter test`
- [ ] Analyze: `cd ../erp_gci_mobile && flutter analyze`
- [ ] Lint: `vendor/bin/pint --dirty --format agent`
- [ ] Manual check: `POST /api/stock-tags/resolve` untuk tag yang sebagian sudah
      dibooking menampilkan `available_qty` < `qty`

## Blocked by

None — can start immediately.

## Files likely touched

- `app/Http/Controllers/Api/MaterialIssueApiController.php`
- `tests/Feature/Api/MaterialIssueApiTest.php`
- `lib/models/resolved_tag.dart`
- `lib/features/issue/issue_bundle_screen.dart`

## Estimated scope

M — 4 file

## Rollback

Field baru bersifat aditif; APK kembali memakai `qty` bila `available_qty`
tidak ada. Tidak ada migration.

## Notes

- `booked` dihitung lewat `ReceiveMaterialService::bookedQtyByStock()` yang sudah
  dipakai endpoint ini — jangan menulis query booking baru.
- Batas di APK bersifat pencegahan dini; server tetap menolak saat release
  karena stok bisa berubah di antara dua panggilan.
