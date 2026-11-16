<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Salinan master troli dari aplikasi Trolley Scan.
 *
 * ERP tidak membuat atau mencetak troli: label dan QR sudah diterbitkan di
 * aplikasi tersebut. Baris di sini hanya dipakai untuk memvalidasi hasil
 * pindai dan menampilkan pilihan troli saat loading.
 */
class Trolley extends Model
{
    public const KIND_LABELS = [
        'reinforce' => 'Reinforce',
        'backplate' => 'Backplate',
        'compbase' => 'CompBase',
    ];

    protected $fillable = [
        'external_id', 'code', 'type', 'kind',
        'capacity', 'location', 'notes', 'synced_at',
    ];

    protected $casts = [
        'external_id' => 'integer',
        'capacity' => 'integer',
        'synced_at' => 'datetime',
    ];

    public function kindLabel(): ?string
    {
        $kind = (string) ($this->kind ?? '');

        return $kind === '' ? null : (self::KIND_LABELS[$kind] ?? ucfirst($kind));
    }
}
