# Task 01: Kategori source pada Master Supplier

**Source:** docs/prd/incoming-po-unification.md — Solution, Technical Decisions,
Success Criteria

**What to build:** Setiap supplier memiliki tepat satu kategori `local` atau
`import` yang dapat dikelola dari Master Supplier, dan supplier lama terisi
melalui aturan klasifikasi yang dapat dipertanggungjawabkan.

## Acceptance criteria

- [ ] `suppliers` memiliki kolom kategori dengan nilai sah `local` atau `import`.
- [ ] Supplier baru wajib menyertakan kategori; nilai kosong atau tidak dikenal
      ditolak dengan error validasi pada field kategori.
- [ ] Supplier yang sudah ada mendapat kategori awal: nama berawalan `PT` menjadi
      `local`.
- [ ] Supplier lain diisi dari riwayat transaksi bila seluruh riwayatnya
      menunjukkan satu source; riwayat kosong atau campuran dibiarkan kosong.
- [ ] Form Master Supplier menampilkan dan menyimpan kategori.
- [ ] Kolom kategori ditampilkan pada daftar supplier.
- [ ] Teks baru tersedia di katalog i18n ID/EN/KO dengan jumlah kunci seimbang.

## Verification

- [ ] Tests pass: `vendor/bin/pest tests/Feature/SupplierSourceTest.php`
- [ ] Build succeeds: `npm run build`
- [ ] Output matches: supplier `PT ...` terisi `local`; supplier tanpa riwayat
      tetap kosong dan tidak dikarang
- [ ] No regressions: `vendor/bin/pest tests/Feature/StockSourceTest.php tests/Feature/LocalPoTest.php`
- [ ] Manual check: buat supplier baru tanpa kategori (harus ditolak), lalu isi
      kategori dan simpan

## Blocked by

None — can start immediately.

## Files likely touched

- `database/migrations/*_add_source_to_suppliers_table.php`
- `app/Models/Supplier.php`
- `app/Http/Controllers/SupplierController.php`
- `resources/js/Pages/Master/Supplier/Index.vue`
- `tests/Feature/SupplierSourceTest.php`

## Estimated scope

M — 5 files

## Rollback

`php artisan migrate:rollback` menghapus kolom kategori. Karena kategori hanya
dipakai untuk filter PO, rollback mengembalikan perilaku lama tanpa merusak
transaksi yang sudah ada.

## Notes

- Aturan nama `PT` hanya untuk **klasifikasi data lama**, bukan untuk supplier
  baru. Supplier baru selalu dipilih manual, sesuai keputusan wawancara.
- Riwayat "campuran" berarti supplier itu pernah dipakai pada transaksi local dan
  import. Kasus ini sengaja dibiarkan kosong agar pengelola memutuskan, bukan
  sistem menebak.
- Backfill harus idempoten agar aman dijalankan ulang.
