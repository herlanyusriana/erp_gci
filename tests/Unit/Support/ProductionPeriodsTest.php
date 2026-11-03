<?php

use App\Support\ProductionPeriods;
use Illuminate\Support\Carbon;

test('shift 1 has seven periods with the break merged into one two hour period', function () {
    $periods = ProductionPeriods::forShift('1');

    expect($periods)->toHaveCount(7)
        ->and($periods[0])->toMatchArray(['start' => '07:30', 'end' => '08:30', 'minutes' => 60])
        ->and($periods[4])->toMatchArray(['start' => '11:30', 'end' => '13:30', 'minutes' => 120])
        ->and($periods[6])->toMatchArray(['start' => '14:30', 'end' => '15:30']);
});

test('shift 2 merges its break across the two half periods', function () {
    $periods = ProductionPeriods::forShift('2');

    expect($periods)->toHaveCount(7);

    $merged = collect($periods)->firstWhere('start', '17:30');

    expect($merged)->not->toBeNull()
        ->and($merged['end'])->toBe('19:30')
        ->and($merged['minutes'])->toBe(120);
});

test('shift 3 crosses midnight and still has seven periods', function () {
    $periods = ProductionPeriods::forShift('3');

    expect($periods)->toHaveCount(7)
        ->and($periods[0])->toMatchArray(['start' => '23:30', 'end' => '00:30'])
        ->and(collect($periods)->firstWhere('start', '03:30'))->toMatchArray(['end' => '05:30', 'minutes' => 120])
        ->and($periods[6])->toMatchArray(['start' => '06:30', 'end' => '07:30']);
});

test('an unknown shift has no periods', function () {
    expect(ProductionPeriods::forShift('9'))->toBe([]);
});

test('only periods that already ended are reportable', function () {
    // 09:30 WIB: periode 07:30-08:30 dan 08:30-09:30 baru saja berakhir.
    $now = Carbon::parse('2026-01-15 02:30:00', 'UTC');

    $reportable = ProductionPeriods::reportableForShift('1', $now, 'Asia/Jakarta');

    expect($reportable)->toHaveCount(2)
        ->and($reportable[0]['start'])->toBe('07:30')
        ->and($reportable[1]['start'])->toBe('08:30');
});

test('a period that is still running is not reportable yet', function () {
    // 09:00 WIB: periode 08:30-09:30 belum berakhir.
    $now = Carbon::parse('2026-01-15 02:00:00', 'UTC');

    expect(ProductionPeriods::reportableForShift('1', $now, 'Asia/Jakarta'))
        ->toHaveCount(1);
});

test('a shift that has not started yet has no reportable period', function () {
    // 15:35 WIB: shift 2 baru mulai 5 menit lalu, belum ada periode yang tutup.
    expect(ProductionPeriods::reportableForShift('2', Carbon::parse('2026-11-03 15:35:00', 'Asia/Jakarta'), 'Asia/Jakarta'))
        ->toBe([]);
});

test('shift 3 does not report future periods right after it starts', function () {
    // 23:45 WIB: shift 3 baru berjalan 15 menit, belum ada periode yang tutup.
    // Sebelum diperbaiki, semua 7 periode tampak sudah berakhir karena jam
    // periode dinihari ditempel ke tanggal hari ini.
    $now = Carbon::parse('2026-11-03 23:45:00', 'Asia/Jakarta');

    expect(ProductionPeriods::reportableForShift('3', $now, 'Asia/Jakarta'))->toBe([]);
});

test('shift 3 reports periods across midnight as the night advances', function () {
    // 01:00 WIB: hanya 23:30-00:30 yang sudah tutup.
    $atOne = Carbon::parse('2026-11-04 01:00:00', 'Asia/Jakarta');
    expect(ProductionPeriods::reportableForShift('3', $atOne, 'Asia/Jakarta'))
        ->toHaveCount(1)
        ->and(ProductionPeriods::reportableForShift('3', $atOne, 'Asia/Jakarta')[0]['start'])->toBe('23:30');

    // 05:00 WIB: empat periode tutup, termasuk periode istirahat 03:30-05:30 belum.
    expect(ProductionPeriods::reportableForShift('3', Carbon::parse('2026-11-04 05:00:00', 'Asia/Jakarta'), 'Asia/Jakarta'))
        ->toHaveCount(4);

    // 07:35 WIB: shift 3 baru saja selesai, semua periode tutup.
    expect(ProductionPeriods::reportableForShift('3', Carbon::parse('2026-11-04 07:35:00', 'Asia/Jakarta'), 'Asia/Jakarta'))
        ->toHaveCount(7);
});

test('the plant timezone argument decides the result, not the app timezone', function () {
    // Jam yang sama, dikirim dalam UTC. Zona pabrik harus tetap menentukan.
    // Sebelum diperbaiki, argumen $zone diabaikan dan hasilnya ikut zona app.
    $now = Carbon::parse('2026-11-04 01:00:00', 'Asia/Jakarta')->utc();

    expect(ProductionPeriods::reportableForShift('3', $now, 'Asia/Jakarta'))->toHaveCount(1);

    // Shift 1 juga harus konsisten: 09:30 WIB = dua periode tutup.
    $shiftOne = Carbon::parse('2026-11-03 09:30:00', 'Asia/Jakarta')->utc();
    expect(ProductionPeriods::reportableForShift('1', $shiftOne, 'Asia/Jakarta'))->toHaveCount(2);
});

test('shift 1 keeps the last period open until the shift really ends', function () {
    // 14:45 WIB: 14:30-15:30 masih berjalan, jadi hanya 6 periode yang tutup.
    expect(ProductionPeriods::reportableForShift('1', Carbon::parse('2026-11-03 14:45:00', 'Asia/Jakarta'), 'Asia/Jakarta'))
        ->toHaveCount(6);

    // 15:35 WIB: shift 1 selesai, periode 14:30-15:30 ikut muncul.
    $after = ProductionPeriods::reportableForShift('1', Carbon::parse('2026-11-03 15:35:00', 'Asia/Jakarta'), 'Asia/Jakarta');
    expect($after)->toHaveCount(7)
        ->and($after[6]['start'])->toBe('14:30')
        ->and($after[6]['end'])->toBe('15:30');
});

test('the session date follows the shift start, not the calendar day', function () {
    // Shift 3 sudah berjalan lewat tengah malam: sesinya tetap milik kemarin.
    expect(ProductionPeriods::sessionDate('3', Carbon::parse('2026-11-04 01:00:00', 'Asia/Jakarta'), 'Asia/Jakarta')->toDateString())
        ->toBe('2026-11-03')
        ->and(ProductionPeriods::sessionDate('3', Carbon::parse('2026-11-04 07:35:00', 'Asia/Jakarta'), 'Asia/Jakarta')->toDateString())
        ->toBe('2026-11-03')
        // Setelah jam mulai hari ini, sesi shift 3 pindah ke hari ini.
        ->and(ProductionPeriods::sessionDate('3', Carbon::parse('2026-11-04 23:45:00', 'Asia/Jakarta'), 'Asia/Jakarta')->toDateString())
        ->toBe('2026-11-04')
        // Shift biasa sebelum jam mulai masih menunjuk sesi kemarin.
        ->and(ProductionPeriods::sessionDate('1', Carbon::parse('2026-11-03 07:00:00', 'Asia/Jakarta'), 'Asia/Jakarta')->toDateString())
        ->toBe('2026-11-02')
        ->and(ProductionPeriods::sessionDate('1', Carbon::parse('2026-11-03 07:35:00', 'Asia/Jakarta'), 'Asia/Jakarta')->toDateString())
        ->toBe('2026-11-03')
        // Shift yang tidak dikenal tidak punya sesi.
        ->and(ProductionPeriods::sessionDate('9', Carbon::parse('2026-11-03 07:35:00', 'Asia/Jakarta'), 'Asia/Jakarta'))
        ->toBeNull();
});
