# Task 05: Checkpoint & Verifikasi End-to-End

**Source:** `docs/prd/wip-label-packaging.md` — Success Criteria, Testing Strategy

**What to build:** Verifikasi end-to-end seluruh alur kemasan label WIP: modal packaging, kalkulasi wadah dan sisa ganjil, multi-page print dengan format kontainer `LABEL 1 / N`, cetak akumulasi NG, serta menjalankan full regression test suite dan static analysis/build.

## Acceptance criteria

- [x] Seluruh Success Criteria di `docs/prd/wip-label-packaging.md` telah terpenuhi dan teruji.
- [x] Test feature Pest di `tests/Feature/ProductionLabelQueueTest.php` mencakup seluruh skenario (kemasan pas, sisa ganjil dicetak, sisa ganjil ditunda, multi-label numbering, dan akumulasi NG).
- [x] `vendor/bin/pint --dirty --format agent` lulus tanpa error formatting.
- [x] `npm run build` lulus typecheck dan bundling production tanpa error.
- [x] Full test suite `./vendor/bin/pest` lulus hijau 100%.

## Verification

- [x] Tests pass: `./vendor/bin/pest tests/Feature/ProductionLabelQueueTest.php`
- [x] Build succeeds: `npm run build`
- [x] Output matches: 100% test suite hijau
- [x] No regressions: `./vendor/bin/pest`
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
