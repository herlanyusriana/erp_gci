# Checkpoint 03: setelah Task 01-04 (incoming-po-unification)

- [x] `vendor/bin/pest tests/Feature/SupplierSourceTest.php tests/Feature/IncomingPoUnificationTest.php` lulus
- [x] Full suite `vendor/bin/pest` lulus tanpa regresi
- [x] `vendor/bin/pint --dirty --format agent` bersih
- [x] `node scripts/check-i18n.mjs` lulus
- [x] `npm run build` lulus
- [ ] Alur end-to-end berjalan: supplier punya kategori → form PO menyaring
      supplier sesuai source → record tersimpan di alur yang benar → daftar
      gabungan menampilkan source → source tidak dapat diubah setelah simpan
- [x] Supplier lama bernama `PT ...` terklasifikasi `local`; supplier ambigu
      tetap kosong
- [x] Local PO tidak muncul di alur Arrival import
- [ ] Review manusia sebelum dianggap selesai

Jika ada yang gagal, perbaiki dulu sebelum melanjutkan.
