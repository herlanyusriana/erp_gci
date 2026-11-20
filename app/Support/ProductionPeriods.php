<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Pembagian waktu kerja dan periode input laporan produksi per hari dan shift.
 *
 * Jadwal pabrik (Senin–Sabtu + Minggu bila lembur):
 * - Senin–Kamis & Minggu (`weekday`):
 *   - Shift 1: 07:30–15:30, istirahat 12:00–13:00 (7 jam, 7 periode; 11:30–13:30 digabung)
 *   - Shift 2: 15:30–23:30, istirahat 18:00–19:00 (7 jam, 7 periode; 17:30–19:30 digabung)
 *   - Shift 3: 23:30–07:30, istirahat 04:00–05:00 (7 jam, 7 periode; 03:30–05:30 digabung)
 * - Jumat (`friday`):
 *   - Shift 1: 07:30–16:00, istirahat 11:30–13:00 (7 jam, 7 periode; istirahat dilompati)
 *   - Shift 2: 16:00–00:00, istirahat 18:00–19:00 (7 jam, 7 periode; istirahat dilompati)
 *   - Shift 3: 00:00–07:30 (mulai Sabtu 00:00), istirahat 04:00–05:00 (6.5 jam, 7 periode; periode akhir 07:00–07:30)
 * - Sabtu (`saturday`):
 *   - Shift 1: 07:30–12:30, tanpa istirahat (5 jam, 5 periode)
 *   - Shift 2: 12:30–17:30, tanpa istirahat (5 jam, 5 periode)
 *   - Shift 3: 19:00–00:00, tanpa istirahat (5 jam, 5 periode)
 */
final class ProductionPeriods
{
    public const DAY_WEEKDAY = 'weekday';

    public const DAY_FRIDAY = 'friday';

    public const DAY_SATURDAY = 'saturday';

    /**
     * Definisi default (Senin–Kamis & Minggu) — dipertahankan sebagai sumber
     * daftar kunci shift (`1`, `2`, `3`) untuk kompatibilitas pemanggil lama.
     *
     * @var array<string, array{start: string, end: string, break_start: string|null, break_end: string|null, work_hours: float, starts_next_day: bool}>
     */
    public const SHIFTS = [
        '1' => [
            'start' => '07:30',
            'end' => '15:30',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'work_hours' => 7.0,
            'starts_next_day' => false,
        ],
        '2' => [
            'start' => '15:30',
            'end' => '23:30',
            'break_start' => '18:00',
            'break_end' => '19:00',
            'work_hours' => 7.0,
            'starts_next_day' => false,
        ],
        '3' => [
            'start' => '23:30',
            'end' => '07:30',
            'break_start' => '04:00',
            'break_end' => '05:00',
            'work_hours' => 7.0,
            'starts_next_day' => false,
        ],
    ];

    /**
     * Jadwal lengkap per jenis hari (`weekday`, `friday`, `saturday`) dan shift.
     *
     * @var array<string, array<string, array{start: string, end: string, break_start: string|null, break_end: string|null, work_hours: float, starts_next_day: bool}>>
     */
    public const SCHEDULES = [
        self::DAY_WEEKDAY => self::SHIFTS,
        self::DAY_FRIDAY => [
            '1' => [
                'start' => '07:30',
                'end' => '16:00',
                'break_start' => '11:30',
                'break_end' => '13:00',
                'work_hours' => 7.0,
                'starts_next_day' => false,
            ],
            '2' => [
                'start' => '16:00',
                'end' => '00:00',
                'break_start' => '18:00',
                'break_end' => '19:00',
                'work_hours' => 7.0,
                'starts_next_day' => false,
            ],
            '3' => [
                'start' => '00:00',
                'end' => '07:30',
                'break_start' => '04:00',
                'break_end' => '05:00',
                'work_hours' => 6.5,
                'starts_next_day' => true,
            ],
        ],
        self::DAY_SATURDAY => [
            '1' => [
                'start' => '07:30',
                'end' => '12:30',
                'break_start' => null,
                'break_end' => null,
                'work_hours' => 5.0,
                'starts_next_day' => false,
            ],
            '2' => [
                'start' => '12:30',
                'end' => '17:30',
                'break_start' => null,
                'break_end' => null,
                'work_hours' => 5.0,
                'starts_next_day' => false,
            ],
            '3' => [
                'start' => '19:00',
                'end' => '00:00',
                'break_start' => null,
                'break_end' => null,
                'work_hours' => 5.0,
                'starts_next_day' => false,
            ],
        ],
    ];

    /** Jenis hari jadwal pabrik untuk tanggal tertentu. */
    public static function dayType(?Carbon $date = null): string
    {
        if ($date === null) {
            return self::DAY_WEEKDAY;
        }

        return match ($date->dayOfWeek) {
            Carbon::FRIDAY => self::DAY_FRIDAY,
            Carbon::SATURDAY => self::DAY_SATURDAY,
            default => self::DAY_WEEKDAY,
        };
    }

    /**
     * Konfigurasi jam kerja untuk shift dan tanggal (atau jenis hari) tertentu.
     *
     * @return array{start: string, end: string, break_start: string|null, break_end: string|null, work_hours: float, starts_next_day: bool, day_type: string, is_holiday: bool}|null
     */
    public static function scheduleFor(string $shift, Carbon|string|null $dateOrDayType = null): ?array
    {
        $dayType = is_string($dateOrDayType)
            ? $dateOrDayType
            : self::dayType($dateOrDayType);

        $config = self::SCHEDULES[$dayType][$shift] ?? null;
        if ($config === null) {
            return null;
        }

        $isHoliday = $dateOrDayType instanceof Carbon && $dateOrDayType->dayOfWeek === Carbon::SUNDAY;

        return $config + [
            'day_type' => $dayType,
            'is_holiday' => $isHoliday,
        ];
    }

    /**
     * Daftar periode untuk sebuah shift pada tanggal atau jenis hari tertentu, berurutan.
     *
     * @return list<array{period: int, start: string, end: string, label: string, minutes: int}>
     */
    public static function forShift(string $shift, Carbon|string|null $dateOrDayType = null): array
    {
        $config = self::scheduleFor($shift, $dateOrDayType);
        if ($config === null) {
            return [];
        }

        $cursor = self::minutes($config['start']);
        $end = self::minutes($config['end']);
        if ($end <= $cursor) {
            $end += 24 * 60;
        }

        $breakStart = $config['break_start'] !== null ? self::minutes($config['break_start']) : null;
        $breakEnd = $config['break_end'] !== null ? self::minutes($config['break_end']) : null;

        if ($breakStart !== null && $breakEnd !== null) {
            if ($breakStart < $cursor) {
                $breakStart += 24 * 60;
            }
            if ($breakEnd <= $breakStart) {
                $breakEnd += 24 * 60;
            }
        }

        $periods = [];
        $periodNo = 1;

        while ($cursor < $end) {
            // Jam istirahat jatuh tepat di batas awal slot (mis. Jumat 11:30, 18:00, 04:00):
            // lompati jam istirahat tanpa digabung ke periode mana pun.
            if ($breakStart !== null && $breakEnd !== null && $cursor === $breakStart) {
                $cursor = $breakEnd;

                continue;
            }

            $next = $cursor + 60;

            // Jam istirahat jatuh di tengah slot 1 jam (mis. Sen–Kam 12:00 dalam 11:30–12:30):
            // gabungkan durasi istirahat ke periode tersebut (11:30–13:30 = 120 menit).
            if ($breakStart !== null && $breakEnd !== null && $breakStart > $cursor && $breakStart < $next) {
                $next = $cursor + 60 + ($breakEnd - $breakStart);
            }

            $next = min($next, $end);

            $periods[] = [
                'period' => $periodNo++,
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
     * Semua tabel periode per jenis hari (`weekday`, `friday`, `saturday`) dan shift.
     *
     * @return array<string, array<string, list<array{period: int, start: string, end: string, label: string, minutes: int}>>>
     */
    public static function allByDayType(): array
    {
        $map = [];
        foreach (array_keys(self::SCHEDULES) as $dayType) {
            foreach (array_keys(self::SHIFTS) as $shiftKey) {
                $shift = (string) $shiftKey;
                $map[$dayType][$shift] = self::forShift($shift, $dayType);
            }
        }

        return $map;
    }

    /**
     * Apakah shift melewati atau berakhir tepat di tengah malam (00:00).
     */
    public static function crossesMidnight(string $shift, Carbon|string|null $dateOrDayType = null): bool
    {
        $config = self::scheduleFor($shift, $dateOrDayType);
        if ($config === null) {
            return false;
        }

        return self::minutes($config['end']) <= self::minutes($config['start']);
    }

    /**
     * Shift yang sedang aktif pada waktu tertentu (zona pabrik).
     * Di jeda antar shift (mis. Sabtu 17:30–19:00), memilih shift yang paling baru berakhir.
     */
    public static function currentShift(Carbon $now, string $zone): string
    {
        $local = $now->copy()->setTimezone($zone);
        $today = $local->copy()->startOfDay();
        $yesterday = $today->copy()->subDay();

        foreach ([$today, $yesterday] as $workDay) {
            foreach (array_keys(self::SHIFTS) as $shiftKey) {
                $shift = (string) $shiftKey;
                $window = self::sessionWindow($shift, $workDay);
                if ($window !== null && $local->greaterThanOrEqualTo($window['start']) && $local->lessThan($window['end'])) {
                    return $shift;
                }
            }
        }

        $latestShift = '1';
        $latestEnd = null;
        foreach ([$today, $yesterday] as $workDay) {
            foreach (array_keys(self::SHIFTS) as $shiftKey) {
                $shift = (string) $shiftKey;
                $window = self::sessionWindow($shift, $workDay);
                if ($window !== null && $window['end']->lessThanOrEqualTo($local)) {
                    if ($latestEnd === null || $window['end']->greaterThan($latestEnd)) {
                        $latestEnd = $window['end'];
                        $latestShift = $shift;
                    }
                }
            }
        }

        return $latestShift;
    }

    /**
     * Tanggal kerja (di zona pabrik) dimulainya sesi shift terakhir yang sudah dimulai.
     *
     * Memperhitungkan jadwal Jumat & Sabtu (termasuk Shift 3 Jumat yang mulai
     * Sabtu 00:00 tepat setelah Shift 2 Jumat selesai).
     */
    public static function sessionDate(string $shift, Carbon $now, string $zone): ?Carbon
    {
        if (! array_key_exists($shift, self::SHIFTS)) {
            return null;
        }

        $local = $now->copy()->setTimezone($zone);
        $today = $local->copy()->startOfDay();

        $todayWindow = self::sessionWindow($shift, $today);
        if ($todayWindow !== null && $local->greaterThanOrEqualTo($todayWindow['start'])) {
            return $today;
        }

        return $today->copy()->subDay();
    }

    /**
     * Waktu mulainya sebuah periode pada sesi shift berjalan, di zona pabrik.
     */
    public static function periodStartAt(string $shift, string $periodStart, Carbon $now, string $zone): ?Carbon
    {
        $sessionDay = self::sessionDate($shift, $now, $zone);
        if ($sessionDay === null) {
            return null;
        }

        $window = self::sessionWindow($shift, $sessionDay);
        $config = self::scheduleFor($shift, $sessionDay);
        if ($window === null || $config === null) {
            return null;
        }

        $startMinutes = self::minutes($config['start']);
        $offset = (self::minutes($periodStart) - $startMinutes + (24 * 60)) % (24 * 60);

        return $window['start']->copy()->addMinutes($offset);
    }

    /**
     * Waktu berakhirnya sebuah periode pada sesi shift berjalan, di zona pabrik.
     */
    public static function periodEndAt(string $shift, string $periodEnd, Carbon $now, string $zone): ?Carbon
    {
        $sessionDay = self::sessionDate($shift, $now, $zone);
        if ($sessionDay === null) {
            return null;
        }

        $window = self::sessionWindow($shift, $sessionDay);
        $config = self::scheduleFor($shift, $sessionDay);
        if ($window === null || $config === null) {
            return null;
        }

        $startMinutes = self::minutes($config['start']);
        $offset = (self::minutes($periodEnd) - $startMinutes + (24 * 60)) % (24 * 60);
        if ($offset === 0) {
            $offset = 24 * 60;
        }

        return $window['start']->copy()->addMinutes($offset);
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
     * @return list<array{period: int, start: string, end: string, label: string, minutes: int, can_report: bool, in_progress: bool}>
     */
    public static function withStatusForShift(string $shift, Carbon $now, string $zone): array
    {
        $local = $now->copy()->setTimezone($zone);
        $sessionDay = self::sessionDate($shift, $local, $zone);
        if ($sessionDay === null) {
            return [];
        }

        return array_map(function (array $period) use ($shift, $local, $zone): array {
            $startAt = self::periodStartAt($shift, $period['start'], $local, $zone);
            $endAt = self::periodEndAt($shift, $period['end'], $local, $zone);

            $ended = $endAt !== null && $local->greaterThanOrEqualTo($endAt);
            $running = ! $ended && $startAt !== null && $local->greaterThanOrEqualTo($startAt);

            return $period + [
                'can_report' => $ended,
                'in_progress' => $running,
            ];
        }, self::forShift($shift, $sessionDay));
    }

    /**
     * Periode yang sudah berakhir dan boleh dilaporkan, untuk sebuah shift.
     *
     * @return list<array{period: int, start: string, end: string, label: string, minutes: int}>
     */
    public static function reportableForShift(string $shift, Carbon $now, string $zone): array
    {
        $sessionDay = self::sessionDate($shift, $now, $zone);
        if ($sessionDay === null) {
            return [];
        }

        return array_values(array_filter(
            self::forShift($shift, $sessionDay),
            fn (array $period): bool => self::hasEnded($shift, $period['end'], $now, $zone),
        ));
    }

    /**
     * Rentang waktu mulai–selesai (timestamp absolut di zona tanggal kerja) untuk satu shift pada hari kerja tertentu.
     *
     * @return array{start: Carbon, end: Carbon}|null
     */
    private static function sessionWindow(string $shift, Carbon $workDay): ?array
    {
        $config = self::scheduleFor($shift, $workDay);
        if ($config === null) {
            return null;
        }

        $startMinutes = self::minutes($config['start']);
        $endMinutes = self::minutes($config['end']);
        $durationMinutes = $endMinutes > $startMinutes
            ? $endMinutes - $startMinutes
            : ($endMinutes + (24 * 60)) - $startMinutes;

        $start = $workDay->copy()->startOfDay();
        if ($config['starts_next_day']) {
            $start->addDay();
        }
        $start->addMinutes($startMinutes);

        return [
            'start' => $start,
            'end' => $start->copy()->addMinutes($durationMinutes),
        ];
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
