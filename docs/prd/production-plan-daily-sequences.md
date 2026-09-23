# PRD: Sequence Harian Production Plan

## Objective

Planner dapat menentukan urutan kerja yang berbeda untuk target D, D+1, dan
D+2 pada setiap baris Production Plan. Sequence diisi manual, dimulai dari `0`,
dan disimpan bersama target harian agar papan tidak memaksakan satu urutan mesin
untuk tiga tanggal yang berbeda.

## Requirements

- Setiap `production_plan_items` memiliki `sequence_d`, `sequence_d1`, dan
  `sequence_d2` berupa integer non-negatif dengan default `0`.
- Input sequence tampil di sel hari yang sama dengan target qty D/D+1/D+2.
- Tombol naik/turun dan nomor urut tampilan lama dihapus dari papan.
- Tombol Simpan menyimpan target dan sequence yang berubah dalam satu request.
- Sequence tidak otomatis mengurutkan baris karena tiga hari dapat memiliki
  urutan berbeda. Urutan visual tetap stabil mengikuti `sequence` lama lalu ID.
- Kolom dan endpoint reorder `sequence` lama dipertahankan untuk kompatibilitas,
  tetapi tidak digunakan oleh UI Production Plan.

## Tech Stack

Laravel 12, PostgreSQL, Inertia 2, Vue 3, TypeScript, Tailwind CSS 3, dan Pest.

## Commands

- Test fokus: `php artisan test --compact tests/Feature/ProductionPlanTest.php`
- Test penuh: `php artisan test --compact`
- Format PHP: `vendor/bin/pint --dirty --format agent`
- Build frontend: `npm run build`

## Project Structure

- `database/migrations/`: migration aditif untuk tiga kolom sequence.
- `app/Models/ProductionPlanItem.php`: fillable dan cast sequence.
- `app/Http/Controllers/ProductionPlanController.php`: validasi dan persistence.
- `resources/js/Pages/Production/Plan/Index.vue`: draft dan input sequence.
- `tests/Feature/ProductionPlanTest.php`: kontrak HTTP dan database.

## Code Style

Ikuti pola target harian yang ada: nama snake_case pada kontrak server dan key
ringkas pada draft Vue. Semua input divalidasi server-side; teks UI memakai
`useI18n()` dan token warna tema yang tersedia.

## Testing Strategy

Feature test membuktikan nilai default, penyimpanan ketiga sequence melalui
endpoint bulk target, dan penolakan integer negatif. Build Vue/TypeScript
membuktikan kontrak props dan template tetap valid.

## Boundaries

- **Always:** otorisasi endpoint tetap dipakai; input sequence divalidasi sebagai
  integer `>= 0`; migration memiliki rollback; target lama tetap berfungsi.
- **Ask first:** menghapus kolom/route `sequence` lama atau mengubah cara urutan
  visual baris.
- **Never:** mempercayai validasi browser, mengubah alur release WO, atau
  mengubah rumus sisa produksi.

## Success Criteria

- [x] Item baru memiliki ketiga sequence bernilai `0`.
- [x] Planner dapat menyimpan nilai sequence berbeda untuk D/D+1/D+2.
- [x] Nilai negatif atau non-integer ditolak tanpa mengubah data.
- [x] Setiap sel hari menampilkan input sequence dan target qty yang dapat diakses.
- [x] Kontrol reorder lama tidak tampil lagi.
- [x] Production Plan tests, full suite, Pint, dan build frontend lulus.

## Open Questions

Tidak ada. Persyaratan dan perilaku kompatibilitas telah disepakati.
