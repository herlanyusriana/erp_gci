# Checkpoint 03: setelah Task 01-04 (incoming-po-unification)

- [ ] `vendor/bin/pest tests/Feature/SupplierSourceTest.php tests/Feature/IncomingPoUnificationTest.php` lulus
- [ ] Full suite `vendor/bin/pest` lulus tanpa regresi
- [ ] `vendor/bin/pint --dirty --format agent` bersih
- [ ] `node scripts/check-i18n.mjs` lulus
- [ ] `npm run build` lulus
- [ ] Alur end-to-end berjalan: supplier punya kategori → form PO menyaring
      supplier sesuai source → record tersimpan di alur yang benar → daftar
      gabungan menampilkan source → source tidak dapat diubah setelah simpan
- [ ] Supplier lama bernama `PT ...` terklasifikasi `local`; supplier ambigu
      tetap kosong
- [ ] Local PO tidak muncul di alur Arrival import
- [ ] Review manusia sebelum dianggap selesai

Jika ada yang gagal, perbaiki dulu sebelum melanjutkan.
