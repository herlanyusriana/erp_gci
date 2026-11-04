<?php

namespace App\Models;

use App\Support\ProductionPeriods;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineDowntime extends Model
{
    public const CATEGORY_QDC = 'qdc';

    public const CATEGORY_DOWNTIME = 'downtime';

    public const CATEGORIES = [
        self::CATEGORY_QDC,
        self::CATEGORY_DOWNTIME,
    ];

    public const REASONS = [
        'qdc',
        'die_trouble',
        'machine_breakdown',
        'waiting_material',
        'waiting_qc',
        'other',
    ];

    protected $fillable = [
        'machine_id',
        'work_order_id',
        'part_id',
        'downtime_date',
        'shift',
        'category',
        'reason',
        'start_time',
        'end_time',
        'duration_minutes',
        'notes',
        'reported_by',
    ];

    protected function casts(): array
    {
        return [
            'downtime_date' => 'date',
            'duration_minutes' => 'integer',
        ];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public static function calculateDurationMinutes(string $startTime, string $endTime, ?string $shift = null): int
    {
        [$sh, $sm] = array_map('intval', explode(':', substr($startTime, 0, 5)));
        [$eh, $em] = array_map('intval', explode(':', substr($endTime, 0, 5)));

        $startMinutes = ($sh * 60) + $sm;
        $endMinutes = ($eh * 60) + $em;

        if ($startMinutes === $endMinutes) {
            return 0;
        }

        if ($endMinutes > $startMinutes) {
            return $endMinutes - $startMinutes;
        }

        if ($shift !== null && ProductionPeriods::crossesMidnight($shift)) {
            return ($endMinutes + (24 * 60)) - $startMinutes;
        }

        return 0;
    }
}
