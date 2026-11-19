# Task 15: Hapus input gross weight dari Arrival & Local PO

**Source:** Keputusan pengguna, lanjutan `2149650` (gross weight sudah dihapus
dari Receive Form/Edit)

**What to build:** Form Arrival (import) dan Local PO berhenti meminta gross
weight, konsisten dengan form Receive yang sudah bersih.

## Acceptance criteria

- [ ] Input `weight_gross` dan labelnya hilang dari `Incoming/Arrival/Form.vue`
      (termasuk baris 846–849) dan `Incoming/LocalPo/Form.vue` (termasuk 359–361).
- [ ] Aturan validasi yang membandingkan `nett > gross` (`Arrival/Form.vue`
      baris 308–309) ikut dihapus agar tidak memvalidasi field yang tidak ada.
- [ ] Payload simpan tidak lagi mengirim `weight_gross` (atau mengirim `null`);
      server tetap menerima `null` karena kolomnya nullable.
- [ ] Kolom `weight_gross` di database **tidak** dihapus; data lama tetap utuh.
- [ ] `npm run build` dan `node scripts/check-i18n.mjs` lulus; key
      `incoming.grossWeight` boleh tetap ada (tidak dipakai) atau dihapus dari
      tiga katalog — pilih satu dan konsisten.

## Verification

- [ ] Build: `npm run build`
- [ ] i18n: `node scripts/check-i18n.mjs`
- [ ] Tests pass: `vendor/bin/pest tests/Feature/IncomingPoArrivalTest.php tests/Feature/LocalPoTest.php tests/Feature/ReceiveFormDefaultsTest.php`
- [ ] Manual check: buka form Arrival baru dan Local PO baru — tidak ada field
      gross weight; simpan berhasil

## Blocked by

None — can start immediately.

## Files likely touched

- `resources/js/Pages/Incoming/Arrival/Form.vue`
- `resources/js/Pages/Incoming/LocalPo/Form.vue`

## Estimated scope

XS — 2 file

## Rollback

Kembalikan blok input dan aturan validasi. Karena kolom DB tetap ada, tidak ada
migrasi yang perlu dibalik.

## Notes

- `Receive/Edit.vue` masih memetakan `gross_weight` dari props tetapi tidak
  menampilkannya; tidak perlu diubah di task ini.
- `MaterialIssueBundleService` masih mengirim `gross_weight` pada data packing
  untuk dokumen cetak. Itu **bukan** form input dan tidak termasuk scope.
