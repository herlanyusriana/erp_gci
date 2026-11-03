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

    /**
     * Apakah shift melewati tengah malam (mis. shift 3: 23:30-07:30).
     *
     * Pada shift seperti ini jam akhir periode boleh lebih kecil daripada jam
     * mulainya, karena periode dinihari jatuh di hari berikutnya.
     */
    public static function crossesMidnight(string $shift): bool
    {
        $config = self::SHIFTS[$shift] ?? null;
        if ($config === null) {
            return false;
        }

        return self::minutes($config['end']) <= self::minutes($config['start']);
    }

    /**
     * Tanggal (di zona pabrik) dimulainya sesi shift terakhir yang sudah mulai.
     *
     * Satu shift berlangsung sekali per hari, mulai dari jam `start`. Untuk
     * menentukan sesi mana yang sedang dibicarakan pada waktu `$now`:
     * - bila jam sekarang sudah lewat jam mulai, sesi shift dimulai hari ini;
     * - bila belum, sesi terakhir dimulai kemarin.
     *
     * Aturan ini berlaku sama untuk shift biasa maupun shift lintas tengah malam
     * (shift 3), sehingga tidak perlu cabang khusus per shift.
     */
    public static function sessionDate(string $shift, Carbon $now, string $zone): ?Carbon
    {
        $config = self::SHIFTS[$shift] ?? null;
        if ($config === null) {
            return null;
        }

        $local = $now->copy()->setTimezone($zone);
        $nowMinutes = ($local->hour * 60) + $local->minute;
        $startMinutes = self::minutes($config['start']);

        $day = $local->copy()->startOfDay();

        return $nowMinutes < $startMinutes ? $day->subDay() : $day;
    }

    /**
     * Waktu berakhirnya sebuah periode pada sesi shift yang berjalan, di zona pabrik.
     *
     * Jam periode dihitung relatif terhadap jam mulai shift dengan pembungkusan
     * 24 jam, sehingga periode dinihari shift 3 otomatis jatuh di hari berikutnya.
     * Menempelkan jam periode ke tanggal hari ini (perilaku lama) membuat semua
     * periode dinihari shift 3 tampak sudah lewat.
     */
    public static function periodEndAt(string $shift, string $periodEnd, Carbon $now, string $zone): ?Carbon
    {
        $config = self::SHIFTS[$shift] ?? null;
        $start = self::sessionDate($shift, $now, $zone);
        if ($config === null || $start === null) {
            return null;
        }

        $startMinutes = self::minutes($config['start']);
        $offset = (self::minutes($periodEnd) - $startMinutes + (24 * 60)) % (24 * 60);

        return $start->copy()->addMinutes($startMinutes + $offset);
    }

    /** Apakah sebuah periode sudah berakhir pada waktu tertentu (zona pabrik). */
    public static function hasEnded(string $shift, string $periodEnd, Carbon $now, string $zone): bool
    {
        $end = self::periodEndAt($shift, $periodEnd, $now, $zone);
        if ($end === null) {
            return false;
        }

        return $now->copy()->setTimezone($zone)->greaterThanOrEqualTo($end);
    }

    /**
     * Semua periode satu shift beserta statusnya pada waktu tertentu.
     *
     * Dipakai layar operator agar seluruh 7 periode tetap terlihat: yang sudah
     * tutup boleh dilaporkan (`can_report`), yang sedang berjalan atau belum
     * mulai ditandai agar operator paham kenapa belum bisa dipilih, bukan
     * sekadar menghilang dari daftar.
     *
     * @return list<array{start: string, end: string, label: string, minutes: int, can_report: bool, in_progress: bool}>
     */
    public static function withStatusForShift(string $shift, Carbon $now, string $zone): array
    {
        $local = $now->copy()->setTimezone($zone);

        return array_map(function (array $period) use ($shift, $local, $zone): array {
            $startAt = self::periodEndAt($shift, $period['start'], $local, $zone);
            $endAt = self::periodEndAt($shift, $period['end'], $local, $zone);

            $ended = $endAt !== null && $local->greaterThanOrEqualTo($endAt);
            // Sedang berjalan: sudah mulai tapi belum tutup.
            $running = ! $ended && $startAt !== null && $local->greaterThanOrEqualTo($startAt);

            return $period + [
                'can_report' => $ended,
                'in_progress' => $running,
            ];
        }, self::forShift($shift));
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
            fn (array $period): bool => self::hasEnded($shift, $period['end'], $now, $zone),
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
