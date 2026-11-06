# Task 04: Penyederhanaan Form Kedatangan (Arrival)

**Source:** `docs/prd/unified-po-arrival-iqc.md` — Technical Decisions, Section 1

**What to build:** Memperbarui `IncomingArrivalController` dan `resources/js/Pages/Incoming/Arrival/Form.vue` untuk menambahkan switcher manual `[ Lokal ]` vs `[ Impor ]`, dropdown pemilih PO (yang otomatis mengisi daftar item barang), serta form khusus lokal yang ringkas (hanya Tanggal, Supplier, No PO, Surat Jalan/Invoice, No PEN, No AJU, dan item barang tanpa kontainer/kapal).

## Acceptance criteria

- [ ] Di bagian atas form Arrival terdapat switcher: `[ Lokal ]` vs `[ Impor ]`.
- [ ] Dropdown `Pilih PO` memfilter PO sesuai jenis (PO lokal vs PO impor).
- [ ] Begitu PO dipilih, sistem otomatis mengisikan (*auto-populate*) seluruh item barang dari PO tersebut ke tabel item.
- [ ] Kuantitas item kedatangan dapat diedit secara parsial jika barang datang bertahap.
- [ ] Jika tipe `Lokal`:
  - Menyembunyikan seluruh panel kontainer, seal code, kapal/vessel, pelabuhan, dan bill of lading.
  - Menampilkan input `No. Surat Jalan / Invoice`, `No. PEN`, dan `No. AJU`.
- [ ] Jika tipe `Impor`:
  - Menampilkan form impor lengkap beserta kontainer dan inspeksi kontainer seperti sebelumnya.

## Verification

- [ ] Tests pass: `./vendor/bin/pest tests/Feature/IncomingArrivalTest.php`
- [ ] Build succeeds: `npm run build`
- [ ] Manual check: Pilih PO lokal -> item terisi otomatis -> simpan dengan No PEN, AJU, Surat Jalan

## Blocked by

Task 02 — Unifikasi Purchase Order (Lokal & Impor)

## Files likely touched

- `app/Http/Controllers/IncomingArrivalController.php`
- `resources/js/Pages/Incoming/Arrival/Form.vue`
- `resources/js/Pages/Incoming/Arrival/Show.vue`
- `resources/js/i18n/catalogs/incoming.ts`

## Estimated scope

M — 4 files

## Rollback

Revert commit perubahan `IncomingArrivalController` dan view Arrival Form.
