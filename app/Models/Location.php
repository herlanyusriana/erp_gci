<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use SoftDeletes;

    /** Tipe lokasi: rak penyimpanan, lokasi mesin, dan area pengiriman. */
    public const TYPE_STORAGE = 'storage';

    public const TYPE_MACHINE = 'machine';

    public const TYPE_DELIVERY_PREP = 'delivery_prep';

    public const TYPES = [self::TYPE_STORAGE, self::TYPE_MACHINE, self::TYPE_DELIVERY_PREP];

    protected $fillable = [
        'code', 'name', 'type', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Isi QR label lokasi. Bentuknya mengikuti label mesin supaya APK bisa
     * memakai pola parse yang sama.
     *
     * @return array{type: string, location_id: int, location_code: string, location_name: string}
     */
    public function qrPayload(): array
    {
        return [
            'type' => 'location',
            'location_id' => (int) $this->id,
            'location_code' => (string) $this->code,
            'location_name' => (string) $this->name,
        ];
    }
}
