<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Periode pelaporan produksi per shift.
 *
 * Aturan yang disepakati di lapangan:
 * - Shift 1: 07:30-15:30, istirahat 12:00-13:00
 * - Shift 2: 15:30-23:30, istirahat 18:00-19:00
 * - Shift 3: 23:30-07:30, istirahat 04:00-05:00
 *
 * Periode dasar satu jam. Periode yang **memuat** jam istirahat digabung menjadi
 * satu periode 2 jam, sehingga setiap shift selalu punya 7 periode dengan pola
 * yang sama dan laporan antar shift bisa dibandingkan.
 */
final class ProductionPeriods
{
    /** @var array<string, array{start: string, end: string, break_start: string, break_end: string}> */
    public const SHIFTS = [
        '1' => ['start' => '07:30', 'end' => '15:30', 'break_start' => '12:00', 'break_end' => '13:00'],
        '2' => ['start' => '15:30', 'end' => '23:30', 'break_start' => '18:00', 'break_end' => '19:00'],
        '3' => ['start' => '23:30', 'end' => '07:30', 'break_start' => '04:00', 'break_end' => '05:00'],
    ];

    /**
     * Daftar periode untuk sebuah shift, berurutan.
     *
     * @return list<array{start: string, end: string, label: string, minutes: int}>
     */
    public static function forShift(string $shift): array
    {
        $config = self::SHIFTS[$shift] ?? null;
        if ($config === null) {
            return [];
        }

        $cursor = self::minutes($config['start']);
        $end = self::minutes($config['end']);
        $breakStart = self::minutes($config['break_start']);
        $breakEnd = self::minutes($config['break_end']);

        // Shift 3 melewati tengah malam: ujung shift dan jam istirahat berada
        // "di hari berikutnya", jadi digeser satu hari agar perbandingan benar.
        if ($end <= $cursor) {
            $end += 24 * 60;
        }
        if ($breakStart < $cursor) {
            $breakStart += 24 * 60;
        }
        if ($breakEnd <= $cursor) {
            $breakEnd += 24 * 60;
        }

        $periods = [];

        while ($cursor < $end) {
            $next = $cursor + 60;

            // Periode yang memuat jam istirahat digabung dengan periode sesudah
            // istirahat: 11:30-13:30 untuk shift 1 (istirahat 12:00-13:00).
            // Hasilnya periode berdurasi satu jam + durasi istirahat.
            $containsBreakStart = $breakStart >= $cursor && $breakStart < $next;
            if ($containsBreakStart) {
                $next = $cursor + 60 + ($breakEnd - $breakStart);
            }

            $next = min($next, $end);

            $periods[] = [
                'start' => self::clock($cursor),
                'end' => self::clock($next),
                'label' => self::clock($cursor).' - '.self::clock($next),
                'minutes' => $next - $cursor,
            ];

            $cursor = $next;
        }

        return $periods;
    }

    /** Apakah sebuah periode sudah berakhir pada waktu tertentu (zona pabrik). */
    public static function hasEnded(string $periodEnd, Carbon $now, string $zone): bool
    {
        $local = $now->copy()->setTimezone($zone);
        $end = $local->copy()->setTimeFromTimeString($periodEnd);

        return $local->greaterThanOrEqualTo($end);
    }

    /**
     * Periode yang sudah berakhir dan boleh dilaporkan, untuk sebuah shift.
     *
     * @return list<array{start: string, end: string, label: string, minutes: int}>
     */
    public static function reportableForShift(string $shift, Carbon $now, string $zone): array
    {
        return array_values(array_filter(
            self::forShift($shift),
            fn (array $period): bool => self::hasEnded($period['end'], $now, $zone),
        ));
    }

    private static function minutes(string $clock): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $clock));

        return ($hour * 60) + $minute;
    }

    private static function clock(int $minutes): string
    {
        $minutes %= 24 * 60;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
