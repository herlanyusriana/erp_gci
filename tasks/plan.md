# Implementation Plan: Sequence Harian Production Plan

PRD: `docs/prd/production-plan-daily-sequences.md`

## Architecture Decisions

- Tambahkan tiga kolom integer non-negatif dengan default database `0`.
- Perluas endpoint bulk target yang sudah ada; tidak membuat endpoint baru.
- Simpan target dan sequence secara atomik melalui transaksi yang sudah ada.
- UI memakai satu draft per baris untuk enam nilai harian.
- Pertahankan urutan visual lama agar tiga sequence independen tidak saling
  memaksakan urutan tabel.

## Ordered Tasks

1. Tambahkan feature test untuk default, persistence, dan validasi sequence.
2. Buat migration, perbarui model, dan perluas validasi/persistence controller.
3. Tambahkan input sequence per hari dan hapus kontrol reorder lama dari UI.
4. Jalankan focused test, full suite, Pint, build, dan review diff.

## Risks and Mitigations

| Risk | Mitigation |
|---|---|
| Nilai sequence negatif/pecahan masuk | Validasi server `integer|min:0` dan test kegagalan. |
| Target lama tidak tersimpan | Perluas payload yang sama dan pertahankan test target lama. |
| Migration pada data existing | Default database `0` mengisi baris lama tanpa backfill terpisah. |
| UI membingungkan antara sequence dan qty | Label/aria-label berbeda dalam setiap sel hari. |

## Verification Checkpoints

- Setelah backend: `php artisan test --compact tests/Feature/ProductionPlanTest.php`
- Setelah UI: `npm run build`
- Final: full suite, Pint, build, dan inspeksi diff.
