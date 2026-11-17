<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Master troli.
 *
 * Data awalnya disalin dari aplikasi Trolley Scan (Export CSV) dan tetap bisa
 * dikelola di ERP lewat Master Data → Troli. Label QR troli tetap diterbitkan
 * di aplikasi Trolley Scan; ERP memakai baris ini untuk memvalidasi hasil
 * pindai dan menampilkan pilihan troli saat loading.
 */
class Trolley extends Model
{
    public const KIND_LABELS = [
        'reinforce' => 'Reinforce',
        'backplate' => 'Backplate',
        'compbase' => 'CompBase',
    ];

    public const TYPES = ['internal', 'external'];

    public const KINDS = ['reinforce', 'backplate', 'compbase'];

    protected $fillable = [
        'external_id', 'code', 'type', 'kind',
        'capacity', 'location', 'notes', 'is_active', 'synced_at',
    ];

    protected $casts = [
        'external_id' => 'integer',
        'capacity' => 'integer',
        'is_active' => 'boolean',
        'synced_at' => 'datetime',
    ];

    /** Troli yang boleh dipilih/dipindai. */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function kindLabel(): ?string
    {
        $kind = (string) ($this->kind ?? '');

        return $kind === '' ? null : (self::KIND_LABELS[$kind] ?? ucfirst($kind));
    }
}
