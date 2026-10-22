# Task 03: Form Material Substitute menampilkan ukuran, UOM, dan packing type

**Source:** docs/prd/substitute-attributes.md — User Stories 1-8, Success Criteria

**What to build:** Pengelola Master Data dapat melihat dan mengisi ukuran, UOM
part pengganti, dan packing type pada halaman Material Substitute, termasuk
menambahkan packing type baru untuk part tersebut.

## Acceptance criteria

- [ ] Form menampilkan input ukuran teks bebas yang boleh dikosongkan.
- [ ] UOM ditampilkan dari master part pengganti sebagai nilai baca-saja; tidak
      ada input UOM terpisah pada relasi.
- [ ] Packing type berupa pilihan tunggal yang berisi kode dari katalog part
      pengganti, wajib dipilih saat menyimpan.
- [ ] Tersedia opsi menambahkan packing type baru untuk part tersebut dari form;
      setelah ditambahkan, kode langsung dapat dipilih.
- [ ] Ukuran kosong ditampilkan sebagai `-` pada tabel daftar.
- [ ] Tabel daftar menampilkan kolom ukuran, UOM, dan packing type.
- [ ] Semua teks baru tersedia dalam katalog i18n ID/EN/KO dengan jumlah kunci
      seimbang.
- [ ] Menambah packing type baru tidak memicu pembuatan record Arrival.

## Verification

- [ ] Tests pass: `vendor/bin/pest tests/Feature/PartSubstituteAttributeTest.php`
- [ ] Build succeeds: `npm run build`
- [ ] Output matches: halaman menerima props katalog packing type per part dan
      UOM part pengganti; `node scripts/check-i18n.mjs` lulus
- [ ] No regressions: `vendor/bin/pest tests/Feature/RolePermissionTest.php`
- [ ] Manual check: buka halaman Substitute, simpan relasi dengan ukuran kosong,
      tambahkan satu packing type baru, lalu pastikan pilihan muncul pada relasi
      part yang sama dan tidak muncul pada part lain

## Blocked by

Task 02 — server harus menerima dan memvalidasi field sebelum form mengirimnya.

## Files likely touched

- `resources/js/Pages/Master/Substitute/Index.vue`
- `app/Http/Controllers/PartSubstituteController.php`
- `resources/js/i18n/catalogs/master.ts`
- `resources/js/types/index.d.ts`
- `tests/Feature/PartSubstituteAttributeTest.php`

## Estimated scope

M — 5 files

## Rollback

Revert commit. Form kembali ke tampilan sebelumnya; data yang sudah tersimpan
tetap ada karena kolomnya nullable.

## Notes

Props halaman perlu memuat katalog packing type per part pengganti. Kirim hanya
katalog untuk part yang relevan agar daftar pilihan tidak membengkak.
