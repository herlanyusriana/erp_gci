# Task 03: Form tambah dan edit BOM di web

**Source:** docs/prd/bom-maintenance.md — User Stories 1-8, Success Criteria

**What to build:** Pengguna dapat menyusun BOM lengkap dari halaman web, baik
untuk BOM baru maupun saat mengedit BOM yang ada, dengan baris item yang dapat
ditambah dan dihapus.

## Acceptance criteria

- [ ] Halaman form menampilkan pilihan part FG yang sudah terdaftar di Master
      Part, dan menonaktifkan pilihan part saat mode edit.
- [ ] Setiap baris item memiliki input sequence, proses, mesin, parent/output
      part, child part, jumlah, UOM, ukuran, special code, dan source.
- [ ] Baris dapat ditambah dan dihapus; minimal satu baris wajib ada.
- [ ] Tombol simpan mengirim ke endpoint create atau update sesuai mode.
- [ ] Error validasi server tampil pada field yang sesuai.
- [ ] Tautan "Tambah BOM" muncul pada halaman daftar BOM hanya bagi pengguna
      berpermission `bom.create`; aksi edit mengikuti permission `bom.update`.
- [ ] Semua teks baru tersedia di katalog i18n ID/EN/KO dengan jumlah kunci
      seimbang.

## Verification

- [ ] Tests pass: `vendor/bin/pest tests/Feature/BomMaintenanceTest.php`
- [ ] Build succeeds: `npm run build`
- [ ] Output matches: halaman menerima props part FG, proses, mesin, dan daftar
      UOM; `node scripts/check-i18n.mjs` lulus
- [ ] No regressions: `vendor/bin/pest tests/Feature/RolePermissionTest.php`
- [ ] Manual check: buat BOM dua baris dari UI, lalu edit dan ubah jumlahnya

## Blocked by

Task 02 — endpoint update dan aturan edit harus tersedia lebih dulu.

## Files likely touched

- `resources/js/Pages/Master/Bom/Form.vue`
- `resources/js/Pages/Master/Bom/Index.vue`
- `resources/js/Pages/Master/Bom/Show.vue`
- `resources/js/i18n/catalogs/master.ts`
- `app/Http/Controllers/BomController.php`

## Estimated scope

M — 5 files

## Rollback

Revert commit. Halaman daftar dan detail kembali seperti semula; data BOM tetap
utuh karena form hanya membaca dan mengirim.

## Notes

Ikuti pola form yang sudah ada di repo (`Master/CycleTime/Index.vue`,
`Incoming/PurchaseOrder/Form.vue`): `useForm`, `InputError`, komponen
`ActionButton`, dan token warna tema.
