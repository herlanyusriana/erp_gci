# Task 17: Key `scanTag`/`saving` + guard paritas katalog

**Source:** `tasks/audit-fixes/mobile-audit.md` — R38, R39

**What to build:** Dua key yang dipakai layar tapi tidak ada di katalog
ditambahkan, dan sebuah test menjaga agar katalog tidak pernah lagi kehilangan
key yang dirujuk kode.

## Latar

`AppLocalizations.t()` mengembalikan key mentah bila tidak ditemukan
(`_values[locale]?[key] ?? _values['id']![key] ?? key`). `scanTag`
(`receipt/confirm_screen.dart:340`) dan `saving`
(`production_session_screen.dart:384,987`) tidak ada di id/en/ko. Akibatnya
tombol utama layar penerimaan berbunyi **"scanTag"**, dan tombol simpan berbunyi
**"saving"** selama proses — di ketiga bahasa. Tidak ada guard yang menangkapnya
karena repo mobile tidak punya pemeriksa paritas seperti
`scripts/check-i18n.mjs` di web.

## Acceptance criteria

- [ ] `scanTag` dan `saving` ada di **id, en, dan ko** dengan terjemahan wajar
      (bukan salinan Indonesia untuk en/ko).
- [ ] Test baru `test/i18n_parity_test.dart` memeriksa:
      (a) jumlah key id/en/ko sama;
      (b) setiap `l.t('literal')` di `lib/**/*.dart` ada di ketiga katalog;
      (c) tidak ada duplikat key dalam satu locale.
- [ ] Test hanya memeriksa argumen literal; pemanggilan dinamis
      (`l.t(errorKey)`, `l.t(_obscure ? ... : ...)`) dilewati secara eksplisit.
- [ ] Test gagal bila satu key dihapus dari salah satu locale (diverifikasi
      manual sekali, lalu dikembalikan).
- [ ] `flutter test` hijau dan `flutter analyze` bersih.

## Verification

- [ ] Tests pass: `cd ../erp_gci_mobile && flutter test test/i18n_parity_test.dart`
- [ ] Full mobile suite: `cd ../erp_gci_mobile && flutter test`
- [ ] Analyze: `cd ../erp_gci_mobile && flutter analyze`
- [ ] Build: `cd ../erp_gci_mobile && flutter build apk --debug`
- [ ] Manual check: buka layar konfirmasi penerimaan pada locale `en` — tombol
      berbunyi "Scan Tag", bukan "scanTag"

## Blocked by

None — can start immediately.

## Files likely touched

- `lib/core/l10n/app_localizations.dart`
- `test/i18n_parity_test.dart` (baru)

## Estimated scope

S — 2 file

## Rollback

Hapus test dan dua key. Layar kembali menampilkan key mentah, seperti sebelum
task ini.

## Notes

- Task ini **tidak** memigrasikan 56 string hardcoded (itu backlog i18n penuh).
  Ia hanya menutup dua key yang sudah dipakai kode dan memasang jaring agar
  tidak terulang.
- 275 key saat ini seimbang sempurna; test harus mempertahankan angka itu
  (menjadi 277 setelah dua key ditambahkan).
