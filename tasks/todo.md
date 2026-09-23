# Task List: Sequence Harian Production Plan

PRD: `docs/prd/production-plan-daily-sequences.md`
Plan: `tasks/plan.md`

## Task 1: Kontrak Backend

- [x] Test membuktikan default ketiga sequence adalah `0`.
- [x] Test membuktikan endpoint bulk menyimpan sequence D/D+1/D+2.
- [x] Test membuktikan sequence negatif ditolak tanpa mengubah data.
- [x] Migration memiliki rollback.
- [x] Model dan controller mengekspos serta menyimpan integer sequence.

Verify: `php artisan test --compact tests/Feature/ProductionPlanTest.php`

## Task 2: UI Sequence Harian

- [x] Input sequence tampil pada setiap sel D/D+1/D+2.
- [x] Sequence dan target disimpan dalam satu aksi Simpan.
- [x] Tombol reorder dan kolom sequence lama dihapus dari UI.
- [x] Label input dapat dibedakan oleh teknologi bantu.

Verify: `npm run build`

## Task 3: Quality Gate

- [x] Production Plan feature tests lulus: 31 test, 353 assertions.
- [x] Full test suite lulus: 145 test, 935 assertions.
- [x] Pint lulus.
- [x] Frontend build lulus.
- [x] Diff direview tanpa perubahan di luar scope.

## Residual Verification

- [ ] Verifikasi visual dan keyboard langsung di browser belum dijalankan karena
  Chrome DevTools MCP tidak tersedia pada sesi ini.
