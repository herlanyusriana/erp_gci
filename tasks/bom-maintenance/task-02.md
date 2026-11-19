# Task 02: Mengedit BOM yang sudah ada

**Source:** docs/prd/bom-maintenance.md — Technical Decisions, User Stories 5,
Success Criteria

**What to build:** Pengguna berpermission update dapat membuka BOM yang ada,
mengubah header dan item-itemnya, lalu menyimpan perubahan pada record BOM yang
sama tanpa membuat versi baru.

## Acceptance criteria

- [x] `GET /boms/{bom}/edit` menampilkan BOM beserta item terurut sequence.
- [x] `PUT /boms/{bom}` memperbarui header dan mengganti daftar item sesuai
      kiriman, tetap pada `bom_id` yang sama.
- [x] Jumlah record BOM untuk part tersebut tetap satu setelah penyimpanan.
- [x] Item yang dihapus dari form tidak tertinggal di database.
- [x] Part pemilik BOM tidak dapat diubah menjadi part non-FG atau part yang
      sudah memiliki BOM lain.
- [x] Pengguna tanpa permission `bom.update` ditolak.

## Verification

- [x] Tests pass: `vendor/bin/pest tests/Feature/BomMaintenanceTest.php`
- [x] Build succeeds: `npm run build`
- [x] Output matches: setelah update, jumlah `boms` untuk part itu tetap 1 dan
      `bom_items` mencerminkan daftar terbaru
- [x] No regressions: `vendor/bin/pest`
- [ ] Manual check: edit BOM, hapus satu item, ubah jumlah item lain, simpan,
      lalu muat ulang halaman

## Blocked by

Task 01 — alur create dan generator nomor harus ada lebih dulu.

## Files likely touched

- `app/Http/Controllers/BomController.php`
- `app/Policies/BomPolicy.php`
- `routes/web.php`
- `tests/Feature/BomMaintenanceTest.php`

## Estimated scope

S — 4 files

## Rollback

Revert commit. Data BOM yang sudah diedit tidak dikembalikan otomatis; pulihkan
dari backup bila perubahan sudah dipakai produksi.

## Notes

Penggantian item dilakukan dalam satu transaksi agar BOM tidak pernah tersimpan
setengah jadi. BOM yang sudah dipakai Work Order perlu dicek dampaknya saat
eksekusi; bila ada dependensi, angkat sebagai temuan sebelum menyimpan
penghapusan item.
