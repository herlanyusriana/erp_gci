# Task 04: Cetak Akumulasi Label NG

**Source:** `docs/prd/wip-label-packaging.md` — Technical Decisions, Section 4

**What to build:** Menyediakan fitur dan aksi pada antrean label WIP di Web untuk mencetak akumulasi reject/NG di akhir shift atau akhir Work Order, sehingga seluruh barang cacat yang terkumpul dalam satu shift/WO dicetak menjadi 1 lembar label NG akhir tanpa harus mencetak per jam.

## Acceptance criteria

- [ ] Pada halaman `Index.vue` disediakan tombol/opsi "Cetak Akumulasi NG" per Work Order atau per Shift.
- [ ] Backend menyediakan endpoint untuk mengakumulasikan seluruh baris `qty_reject` yang belum dicetak pada WO/Shift tersebut.
- [ ] Label NG yang dicetak memuat total kuantitas reject gabungan, rincian periode yang digabung, dan nomor WO.
- [ ] Baris-baris laporan yang reject-nya sudah dicetak secara akumulatif ditandai agar tidak dicetak dobel.

## Verification

- [ ] Tests pass: `./vendor/bin/pest tests/Feature/ProductionLabelQueueTest.php`
- [ ] Build succeeds: `npm run build`
- [ ] Output matches: tombol cetak akumulasi NG berfungsi dan menghasilkan label NG gabungan
- [ ] No regressions: `./vendor/bin/pest`
- [ ] Manual check: klik Cetak Akumulasi NG menghasilkan 1 label dengan total akumulasi reject

## Blocked by

Task 01 — Database & Model Carry-Over

## Files likely touched

- `app/Http/Controllers/ProductionLabelController.php`
- `resources/js/Pages/Production/Label/Index.vue`
- `resources/views/production/labels/wip.blade.php`
- `tests/Feature/ProductionLabelQueueTest.php`

## Estimated scope

S — 3 files

## Rollback

Revert commit endpoint akumulasi NG dan tombol UI terkait.
