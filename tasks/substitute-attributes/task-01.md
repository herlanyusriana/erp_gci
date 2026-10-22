# Task 01: Katalog packing type per part dan skema atribut substitute

**Source:** docs/prd/substitute-attributes.md — Technical Decisions, Success Criteria

**What to build:** Sistem dapat menyimpan daftar packing type yang berlaku untuk
suatu part, diambil dari riwayat item Arrival dan dari penambahan manual, lalu
menyediakan daftar itu untuk dipakai relasi substitute. Relasi substitute juga
memperoleh kolom ukuran dan packing type.

## Acceptance criteria

- [ ] Tabel katalog packing type per part menyimpan `part_id`, kode packing, dan
      asal nilai (riwayat Arrival atau tambahan manual), unik per part + kode.
- [ ] Nilai `unit_bundle` dari item Arrival yang sudah ada menjadi sumber katalog
      untuk part pada item tersebut, tanpa menggandakan kode yang sama.
- [ ] Kode packing type dapat ditambahkan manual untuk satu part dan tersedia
      kembali pada pemakaian berikutnya, tanpa membuat baris Arrival baru.
- [ ] Katalog untuk satu part tidak bocor menjadi pilihan part lain.
- [ ] `part_substitutes` memiliki kolom ukuran bertipe teks nullable dan kolom
      packing type.
- [ ] Relasi lama dengan tepat satu nilai packing historis unik terisi otomatis;
      relasi dengan beberapa nilai atau tanpa nilai dibiarkan kosong untuk diisi
      pengelola.
- [ ] Migrasi dapat dibalik tanpa kehilangan data relasi substitute.

## Verification

- [ ] Tests pass: `vendor/bin/pest tests/Feature/PartSubstituteAttributeTest.php`
- [ ] Build succeeds: `npm run build`
- [ ] Output matches: katalog per part berisi kode gabungan Arrival dan manual,
      terdeduplikasi, dan backfill mengisi hanya relasi bernilai tunggal
- [ ] No regressions: `vendor/bin/pest`
- [ ] Manual check: periksa isi tabel katalog untuk satu part yang punya riwayat
      Arrival dan satu part tanpa riwayat

## Blocked by

None — can start immediately.

## Files likely touched

- `database/migrations/*_create_part_packing_types_table.php`
- `database/migrations/*_add_size_and_packing_type_to_part_substitutes.php`
- `app/Models/PartPackingType.php`
- `app/Models/PartSubstitute.php`
- `tests/Feature/PartSubstituteAttributeTest.php`

## Estimated scope

M — 5 files

## Rollback

`php artisan migrate:rollback` untuk kedua migrasi. Backfill hanya mengisi kolom
nullable, sehingga rollback tidak menghapus data relasi; katalog packing type
hilang bersama tabelnya dan dapat dibangun ulang dari riwayat Arrival.

## Notes

- Sumber kebenaran packing type adalah part, bukan supplier, sesuai keputusan
  wawancara. Katalog manual sengaja terpisah dari item Arrival agar tidak
  menciptakan transaksi kedatangan palsu.
- Backfill memakai riwayat `incoming_arrival_items.unit_bundle` yang sudah ada.
  Supplier tanpa riwayat tidak dikarang nilainya.
- Aturan ini menutup Open Question migrasi pada PRD: nilai tunggal unik diisi,
  nilai ganda diserahkan ke pengelola.
