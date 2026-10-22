# Task 04: Source PO terkunci setelah disimpan

**Source:** docs/prd/incoming-po-unification.md — User Story 9, Technical
Decisions, Success Criteria

**What to build:** Source yang sudah tersimpan pada sebuah PO tidak dapat diubah
lagi. Kesalahan source ditangani dengan membatalkan record dan mencatat ulang,
bukan dengan memindahkan record antar lifecycle.

## Acceptance criteria

- [ ] Mengubah source pada record PO yang sudah ada ditolak dengan error
      validasi, bukan diam-diam diabaikan.
- [ ] Form edit menampilkan source sebagai nilai terkunci, bukan pilihan bebas.
- [ ] Membatalkan/menghapus record tetap memungkinkan pencatatan ulang dengan
      source yang benar.
- [ ] Penolakan berlaku juga bila request dikirim langsung tanpa UI.

## Verification

- [ ] Tests pass: `vendor/bin/pest tests/Feature/IncomingPoUnificationTest.php`
- [ ] Build succeeds: `npm run build`
- [ ] Output matches: record dengan source berbeda menghasilkan session error dan
      data tidak berubah
- [ ] No regressions: `vendor/bin/pest`
- [ ] Manual check: buka edit PO, pastikan source tidak dapat diubah

## Blocked by

Task 02 — alur simpan dengan source harus ada lebih dulu.

## Files likely touched

- `app/Http/Controllers/PurchaseOrderController.php`
- `app/Http/Controllers/LocalPoController.php`
- `resources/js/Pages/Incoming/PurchaseOrder/Form.vue`
- `tests/Feature/IncomingPoUnificationTest.php`

## Estimated scope

S — 4 files

## Rollback

Revert commit. Source kembali dapat diubah seperti perilaku sebelumnya.

## Notes

Validasi dilakukan di server sebagai guard utama. Mengunci di UI saja tidak
cukup karena request dapat dikirim langsung.
