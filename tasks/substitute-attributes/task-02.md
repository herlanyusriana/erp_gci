# Task 02: Server menerima ukuran dan packing type pada relasi substitute

**Source:** docs/prd/substitute-attributes.md — Solution, Technical Decisions,
Success Criteria

**What to build:** Pengelola dapat menyimpan relasi substitute dengan ukuran
opsional dan satu packing type wajib yang valid untuk part pengganti, melalui
endpoint yang sudah ada. Nilai yang tidak terkait part tersebut ditolak.

## Acceptance criteria

- [ ] `POST /substitutes` menerima dan menyimpan `size` (boleh kosong) serta
      `packing_type` untuk relasi part + substitute part + supplier.
- [ ] `PUT /substitutes/{id}` memperbarui ukuran dan packing type pada record
      yang sama.
- [ ] Packing type yang tidak terdaftar pada katalog part pengganti ditolak
      dengan pesan validasi pada field `packing_type`.
- [ ] Relasi baru tanpa packing type ditolak; relasi lama yang belum punya nilai
      tetap dapat disimpan setelah pengelola memilih packing type.
- [ ] `supplier_id` divalidasi sebagai supplier yang ada; relasi tidak dibuat
      atau ditimpa dengan supplier kosong.
- [ ] Ukuran kosong tersimpan sebagai null, bukan string kosong atau tanda
      hubung.
- [ ] UOM tidak disimpan ulang pada relasi; nilainya tetap dibaca dari master
      part pengganti.

## Verification

- [ ] Tests pass: `vendor/bin/pest tests/Feature/PartSubstituteAttributeTest.php`
- [ ] Build succeeds: `npm run build`
- [ ] Output matches: record tersimpan memuat size dan packing_type yang dikirim;
      packing type di luar katalog menghasilkan session error
- [ ] No regressions: `vendor/bin/pest tests/Feature/PartPriceTest.php tests/Feature/WorkOrderAllocationTest.php tests/Feature/LocalPoTest.php`
- [ ] Manual check: simpan relasi dengan ukuran kosong, lalu pastikan tampil
      sebagai kosong di database

## Blocked by

Task 01 — katalog packing type dan kolom skema harus ada lebih dulu.

## Files likely touched

- `app/Http/Controllers/PartSubstituteController.php`
- `app/Models/PartSubstitute.php`
- `tests/Feature/PartSubstituteAttributeTest.php`

## Estimated scope

S — 3 files

## Rollback

Revert commit. Kolom tetap ada dan nullable, jadi data lama tidak rusak; validasi
baru hilang bersama kode.

## Notes

**Defect yang ditemukan saat breakdown:** `store()` saat ini memakai
`$data['supplier_id']` pada `updateOrCreate` tanpa memvalidasinya, sementara
`update()` sudah memvalidasi. Akibatnya submit tanpa supplier dapat membuat
relasi ber-supplier null atau menimpa relasi lain yang kuncinya bertabrakan.
Perbaikan ini termasuk dalam task ini karena supplier adalah bagian kunci relasi.

Pesan validasi baru memakai `__()` dengan katalog `lang/{id,en,ko}.json`.
