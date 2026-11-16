<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Master troli (dari aplikasi Trolley Scan)
    |--------------------------------------------------------------------------
    |
    | Master dan label QR diterbitkan di aplikasi Trolley Scan. ERP hanya
    | menyimpan salinan lewat `php artisan db:seed --class=TrolleySeeder`
    | (atau `php artisan trolleys:import <csv>`), lalu memvalidasi hasil pindai.
    |
    | Status in/out tidak disimpan karena itu pergerakan, bukan master.
    |
    */

    'require_master' => (bool) env('TROLLEY_REQUIRE_MASTER', true),
];
