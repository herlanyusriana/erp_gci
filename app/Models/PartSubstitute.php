<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class PartSubstitute extends Model
{
    protected $fillable = [
        'part_id', 'substitute_part_id', 'supplier_id',
        'material_group', 'size', 'packing_type', 'source', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'part_id');
    }

    public function substitutePart(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'substitute_part_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * UOM mengikuti master part pengganti, bukan disimpan ulang di relasi.
     */
    public function substituteUomCode(): ?string
    {
        return $this->substitutePart?->uom?->code;
    }

    /**
     * Isi packing_type hanya bila part pengganti punya tepat satu kode historis.
     * Nilai ganda atau kosong sengaja dibiarkan null agar pengelola yang memilih;
     * sistem tidak menebak.
     *
     * @return int jumlah relasi yang terisi
     */
    public static function backfillPackingType(): int
    {
        $candidates = DB::table('part_packing_types')
            ->select('part_id', DB::raw('COUNT(*) AS total'), DB::raw('MIN(code) AS code'))
            ->groupBy('part_id')
            ->havingRaw('COUNT(*) = 1')
            ->get();

        $filled = 0;

        foreach ($candidates as $candidate) {
            $filled += DB::table('part_substitutes')
                ->where('substitute_part_id', $candidate->part_id)
                ->whereNull('packing_type')
                ->update(['packing_type' => $candidate->code]);
        }

        return $filled;
    }

    /**
     * Size otoritatif untuk pasangan supplier + part.
     *
     * Dipakai saat menyimpan dokumen (PO, Local PO) supaya size tidak pernah
     * menyimpang dari master: kolom relasi khusus supplier menang, lalu master
     * part. `null` berarti keduanya belum diisi.
     */
    public static function resolveSize(int $supplierId, int $partId): ?string
    {
        $relation = static::query()
            ->where('is_active', true)
            ->where('supplier_id', $supplierId)
            ->where('substitute_part_id', $partId)
            ->orderByRaw("NULLIF(TRIM(COALESCE(size, '')), '') IS NULL")
            ->first();

        foreach ([$relation?->size, Part::query()->whereKey($partId)->value('size')] as $candidate) {
            $value = trim((string) $candidate);

            if ($value !== '' && $value !== '-') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Size efektif satu relasi: kolom relasi (khusus supplier) dulu, lalu
     * master part pengganti. Placeholder `-` dianggap kosong.
     */
    public static function effectiveSize(self $relation): ?string
    {
        foreach ([$relation->size, $relation->substitutePart?->size] as $candidate) {
            $value = trim((string) $candidate);

            if ($value !== '' && $value !== '-') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Daftar part per supplier (sumber tunggal part↔supplier), untuk dropdown
     * part yang difilter berdasarkan supplier terpilih.
     *
     * `size` dan `model` ikut dikirim supaya form PO/Local PO bisa mengisi
     * otomatis dari master, bukan diketik manual.
     *
     * @return list<array{supplier_id:int, part_id:int, part_number:string|null, part_name:string|null, model:string|null, size:string|null, uom:string|null}>
     */
    public static function supplierParts(): array
    {
        return static::query()
            ->where('is_active', true)
            ->whereNotNull('supplier_id')
            ->with([
                'substitutePart:id,part_number,part_name,model,size,uom_id',
                'substitutePart.uom:id,code',
            ])
            ->get()
            ->map(fn (self $s) => [
                'supplier_id' => (int) $s->supplier_id,
                'part_id' => (int) $s->substitute_part_id,
                'part_number' => $s->substitutePart?->part_number,
                'part_name' => $s->substitutePart?->part_name,
                'model' => $s->substitutePart?->model,
                // Size khusus supplier menang; kalau kosong pakai master part
                // supaya PO/Local PO tidak pernah menebak sendiri.
                'size' => self::effectiveSize($s),
                'uom' => $s->substitutePart?->uom?->code,
            ])
            ->unique(fn (array $r) => $r['supplier_id'].'|'.$r['part_id'])
            ->values()
            ->all();
    }
}
