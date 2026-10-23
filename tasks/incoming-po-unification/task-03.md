# Task 03: Titik masuk yang menyatakan perbedaan alur import dan local

**Source:** docs/prd/incoming-po-unification.md — User Story 8, Success Criteria

**What to build:** Pengguna Incoming langsung mengenali bahwa PO import dan PO
local adalah dua alur berbeda dengan kelanjutan yang berbeda, tanpa tabel
gabungan yang memaksakan kolom seragam.

## Acceptance criteria

- [ ] Titik masuk Incoming menyatakan bahwa PO import berlanjut ke Arrival
      (BL/PEN/AJU, vessel, container).
- [ ] Titik masuk Incoming menyatakan bahwa PO local tanpa langkah Arrival dan
      langsung ke penerimaan.
- [ ] PO import dan PO local tetap memakai halaman dan kolom daftarnya sendiri.
- [ ] Form PO hanya menampilkan supplier `import`; form Local PO hanya supplier
      `local` (dari Task 02).
- [ ] Teks baru tersedia di katalog i18n ID/EN/KO dengan jumlah kunci seimbang.

## Verification

- [ ] Tests pass: `vendor/bin/pest tests/Feature/IncomingPoUnificationTest.php`
- [ ] Build succeeds: `npm run build`
- [ ] Output matches: keterangan tile menyatakan kelanjutan masing-masing alur
- [ ] No regressions: `vendor/bin/pest tests/Feature/LocalPoTest.php`
- [ ] Manual check: buka menu Incoming; perbedaan alur terbaca tanpa membuka
      halaman detail

## Blocked by

Task 02 — pemisahan form dan validasi supplier harus ada lebih dulu.

## Files likely touched

- `resources/js/Pages/Incoming/ModuleLauncher.vue`
- `resources/js/i18n/catalogs/incoming.ts`
- `tests/Feature/IncomingPoUnificationTest.php`

## Estimated scope

S — 3 files

## Rollback

Revert commit. Keterangan tile kembali seperti sebelumnya; alur PO dan validasi
tidak berubah karena perbaikan ini hanya teks.

## Notes

- **Koreksi arah dari wawancara (2026-10-26):** PRD awal menjanjikan satu daftar
  gabungan, tetapi investigasi membuktikan dua alur punya dokumen berbeda jauh
  (Local PO 6 field vs Arrival 18 field). Satu tabel akan merugikan kedua sisi.
  PBJ mengubah Task 03 dari "tabel gabungan" menjadi "titik masuk eksplisit".
- Source record ditentukan oleh kategori supplier-nya (keputusan yang tidak
  berubah).
