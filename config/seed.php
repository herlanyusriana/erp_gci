<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Kredensial seeder
    |--------------------------------------------------------------------------
    |
    | Password akun yang dibuat seeder diambil dari environment di luar
    | local/testing. Isi variabel ini sebelum menjalankan
    | `php artisan migrate --seed` di server trial/produksi.
    |
    | Di local/testing nilai kosong memakai fallback lama (`password` untuk
    | admin & user demo, `gci2026` untuk operator produksi).
    |
    */

    // Password admin@geumcheon.local (wajib di luar local/testing).
    'admin_password' => env('SEED_ADMIN_PASSWORD'),

    // Password user demo per role (hanya dipakai bila user demo diizinkan).
    'demo_password' => env('SEED_DEMO_PASSWORD'),

    // Password karyawan produksi (akun APK, wajib di luar local/testing).
    'operator_password' => env('SEED_OPERATOR_PASSWORD'),

    // Izinkan user demo per role dibuat di luar local/testing.
    'allow_demo_users' => (bool) env('SEED_ALLOW_DEMO_USERS', false),
];
