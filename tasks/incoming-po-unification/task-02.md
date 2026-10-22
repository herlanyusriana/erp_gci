# Task 02: Form PO dengan source dan supplier terfilter

**Source:** docs/prd/incoming-po-unification.md — Solution, User Stories 1-5,
Success Criteria

**What to build:** Pengguna mencatat PO supplier dari satu form, memilih source
`local` atau `import`, dan hanya melihat supplier dengan kategori yang cocok.
Server menegakkan aturan yang sama meski request dikirim langsung.

## Acceptance criteria

- [ ] Form PO menampilkan pilihan source `local` atau `import` sebagai field
      wajib.
- [ ] Dropdown supplier hanya berisi supplier yang kategorinya sama dengan source
      terpilih; mengganti source mengosongkan supplier dan baris item.
- [ ] Request dengan supplier yang kategorinya tidak cocok ditolak server dengan
      error validasi pada field supplier.
- [ ] Source `import` menyimpan record Purchase Order dan tidak membuat Arrival
      secara otomatis.
- [ ] Source `local` mengikuti alur Local PO dan tidak diarahkan ke Arrival
      import.
- [ ] Field khusus tiap source tetap seperti sekarang; penyatuan hanya pada
      entry point dan form.
- [ ] Teks baru tersedia di katalog i18n ID/EN/KO dengan jumlah kunci seimbang.

## Verification

- [ ] Tests pass: `vendor/bin/pest tests/Feature/IncomingPoUnificationTest.php`
- [ ] Build succeeds: `npm run build`
- [ ] Output matches: props supplier terfilter per source; record tersimpan pada
      alur yang benar sesuai source
- [ ] No regressions: `vendor/bin/pest tests/Feature/LocalPoTest.php tests/Feature/PurchaseOrderSupplierPartTest.php`
- [ ] Manual check: catat PO import dengan supplier import, lalu PO local dengan
      supplier local; pastikan masing-masing masuk alur yang benar

## Blocked by

Task 01 — kolom kategori supplier dan backfill harus ada lebih dulu.

## Files likely touched

- `app/Http/Controllers/PurchaseOrderController.php`
- `app/Http/Controllers/LocalPoController.php`
- `resources/js/Pages/Incoming/PurchaseOrder/Form.vue`
- `resources/js/Pages/Incoming/LocalPo/Form.vue`
- `tests/Feature/IncomingPoUnificationTest.php`

## Estimated scope

L — 5 files

## Rollback

Revert commit. Kedua form kembali terpisah seperti semula; record PO yang sudah
tersimpan tetap dapat dibuka karena alur source-specific tidak dihapus.

## Notes

- Penyatuan sengaja **tidak** menyamakan lifecycle. Import tetap lanjut ke
  Arrival terpisah; local tetap di Local PO. Menyatukan keduanya ditandai
  out-of-scope di PRD.
- Validasi server adalah guard utama; filter dropdown hanya kenyamanan UI.
- Bila form disatukan secara komponen, jaga agar field dan validasi khusus tiap
  source tidak hilang saat refactor.
