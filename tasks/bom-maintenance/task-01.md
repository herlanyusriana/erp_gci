# Task 01: Membuat BOM lengkap untuk part FG

**Source:** docs/prd/bom-maintenance.md — Solution, Technical Decisions,
Success Criteria

**What to build:** Pengguna berpermission BOM dapat menyimpan BOM lengkap untuk
part FG yang sudah terdaftar, beserta item routing-nya, melalui web ERP. Nomor
BOM dibuat otomatis dan BOM langsung aktif.

## Acceptance criteria

- [ ] `GET /boms/create` menampilkan form BOM baru bagi pengguna berpermission
      `bom.create`.
- [ ] `POST /boms` menyimpan satu header BOM untuk part FG beserta item-itemnya
      dalam satu transaksi.
- [ ] `bom_no` dibuat otomatis dan unik, termasuk setelah BOM lama dihapus.
- [ ] Part yang tidak ada atau bertipe selain FG ditolak dengan error validasi.
- [ ] Part FG yang sudah memiliki BOM ditolak pada alur create, karena
      `boms.part_id` unik dan perubahan dilakukan lewat edit.
- [ ] Setiap item menyimpan sequence, proses, mesin, parent/output part, child
      part, jumlah, UOM, ukuran, special code, dan source sesuai yang dikirim.
- [ ] BOM tersimpan dengan `is_active = true` dan langsung terbaca oleh alur yang
      memakai BOM aktif.
- [ ] Pengguna tanpa permission `bom.create` ditolak.

## Verification

- [ ] Tests pass: `vendor/bin/pest tests/Feature/BomMaintenanceTest.php`
- [ ] Build succeeds: `npm run build`
- [ ] Output matches: record `boms` dan `bom_items` tersimpan lengkap; `bom_no`
      berurutan dan tidak bentrok setelah penghapusan
- [ ] No regressions: `vendor/bin/pest tests/Feature/PartTypeGuardTest.php`
- [ ] Manual check: buat BOM FG baru dengan dua item proses, lalu buka detailnya

## Blocked by

None — can start immediately.

## Files likely touched

- `app/Http/Controllers/BomController.php`
- `app/Models/Bom.php`
- `app/Policies/BomPolicy.php`
- `routes/web.php`
- `tests/Feature/BomMaintenanceTest.php`

## Estimated scope

M — 5 files

## Rollback

Revert commit dan hapus route `boms.create`/`boms.store`. Data BOM yang sudah
tersimpan tidak terpengaruh karena hanya penambahan alur.

## Notes

- `boms.part_id` sudah UNIQUE, jadi satu FG hanya punya satu BOM. Ini konsisten
  dengan keputusan "edit record yang sama", bukan versi baru.
- Nomor BOM mengikuti pola generator yang sudah ada di repo: `withTrashed()`
  agar nomor tidak terpakai ulang setelah soft delete.
- **Open Question PRD masih terbuka:** field wajib per item. Implementasi memakai
  validasi minimal (child part dan jumlah wajib) dan menandainya di kode serta
  test agar mudah disesuaikan saat aturan final ditetapkan.
