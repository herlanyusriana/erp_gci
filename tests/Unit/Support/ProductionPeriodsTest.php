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

test('no period is reportable before the shift starts', function () {
    $now = Carbon::parse('2026-01-15 23:00:00', 'UTC');

    expect(ProductionPeriods::reportableForShift('1', $now, 'Asia/Jakarta'))->toBe([]);
});
