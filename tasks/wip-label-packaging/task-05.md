# Task 05: Checkpoint & Verifikasi End-to-End

**Source:** `docs/prd/wip-label-packaging.md` — Success Criteria, Testing Strategy

**What to build:** Verifikasi end-to-end seluruh alur kemasan label WIP: modal packaging, kalkulasi wadah dan sisa ganjil, multi-page print dengan format kontainer `LABEL 1 / N`, cetak akumulasi NG, serta menjalankan full regression test suite dan static analysis/build.

## Acceptance criteria

- [ ] Seluruh Success Criteria di `docs/prd/wip-label-packaging.md` telah terpenuhi dan teruji.
- [ ] Test feature Pest di `tests/Feature/ProductionLabelQueueTest.php` mencakup seluruh skenario (kemasan pas, sisa ganjil dicetak, sisa ganjil ditunda, multi-label numbering, dan akumulasi NG).
- [ ] `vendor/bin/pint --dirty --format agent` lulus tanpa error formatting.
- [ ] `npm run build` lulus typecheck dan bundling production tanpa error.
- [ ] Full test suite `./vendor/bin/pest` lulus hijau 100%.

## Verification

- [ ] Tests pass: `./vendor/bin/pest tests/Feature/ProductionLabelQueueTest.php`
- [ ] Build succeeds: `npm run build`
- [ ] Output matches: 100% test suite hijau
- [ ] No regressions: `./vendor/bin/pest`
- [ ] Manual check: review diff dan laporan hasil eksekusi ke user

## Blocked by

Task 03, Task 04

## Files likely touched

- `tests/Feature/ProductionLabelQueueTest.php`
- `docs/prd/wip-label-packaging.md`

## Estimated scope

S — 2 files

## Rollback

Revert commit verifikasi jika ditemukan regresi.
