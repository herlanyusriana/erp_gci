<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Machine extends Model
{
    use SoftDeletes;

    /**
     * Urutan tampil mesin di papan Production Plan (nama mesin => sequence),
     * mengikuti kolom "No." daftar master produksi. Celah nomor dijaga apa
     * adanya (13 kosong, 14 = AMADA, 17 = NOGUCHI).
     *
     * @var array<string, int>
     */
    public const DISPLAY_SEQUENCE = [
        'TPL KUKIL' => 1,
        'ASSY. TPL COMP BASE 1' => 2,
        'AUTO CAULKING' => 3,
        'ASSY. TPL COMP BASE 2' => 4,
        'SPOT WELDING 4 -  40 KVA' => 5,
        'SPOT WELDING 3 -  40 KVA' => 6,
        'SPOT WELDING 2 -  40 KVA' => 7,
        'SPOT WELDING 1 -  40 KVA' => 8,
        'ASSEMBLING RF' => 9,
        'TPL DONGSHIN' => 10,
        'ASSY. TPL DONGSHIN' => 11,
        'ASSEMBLING SMALL PART' => 12,
        'AMADA 150 TON' => 14,
        'SHANGYANG 25 TON' => 15,
        'WASINO 150 TON' => 16,
        'NOGUCHI 80 TON' => 17,
        'KUKIL 110 TON' => 18,
        'KUKIL 160 TON' => 19,
        'JENJI 200 TON' => 20,
        'KYOKUTO 300 TON' => 21,
        'AIDA 60 TON' => 22,
        'AIDA 100 TON' => 23,
        'HANOUL 250 TON' => 24,
        'KUKIL 400 TON' => 25,
        'SPOT WELDING 5 - 35 KVA' => 26,
        'WELDING ROBOT' => 27,
        'BARREL' => 28,
        'TAPPING 2' => 29,
    ];

    protected $fillable = [
        'machine_code', 'machine_name', 'sequence', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function processes(): BelongsToMany
    {
        return $this->belongsToMany(Process::class, 'machine_process');
    }
}
