<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Supplier extends Model
{
    use SoftDeletes;

    /** Supplier dalam negeri. */
    public const SOURCE_LOCAL = 'local';

    /** Supplier luar negeri. */
    public const SOURCE_IMPORT = 'import';

    /** @var list<string> */
    public const SOURCES = [self::SOURCE_LOCAL, self::SOURCE_IMPORT];

    protected $fillable = [
        'supplier_code', 'supplier_name', 'source', 'address', 'phone', 'email', 'contact_person',
        'bank_account', 'signature_path', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $appends = ['signature_url'];

    /**
     * URL publik gambar ttd (storage/app/public + symlink, tanpa leading slash).
     */
    public function getSignatureUrlAttribute(): ?string
    {
        return $this->signature_path ? Storage::disk('public')->url($this->signature_path) : null;
    }

    public function partSubstitutes(): HasMany
    {
        return $this->hasMany(PartSubstitute::class);
    }

    /**
     * Klasifikasi awal untuk supplier lama.
     *
     * Nama berawalan "PT" atau "PT." menandakan badan usaha dalam negeri. Supplier
     * lain hanya diisi bila seluruh riwayatnya menunjukkan satu source; riwayat
     * kosong atau campuran sengaja dibiarkan null agar pengelola memutuskan.
     *
     * @return array{local:int, import:int, skipped:int}
     */
    public static function backfillSource(): array
    {
        $stats = ['local' => 0, 'import' => 0, 'skipped' => 0];

        static::query()->whereNull('source')->orderBy('id')->each(function (self $supplier) use (&$stats): void {
            $source = static::detectSource($supplier);

            if ($source === null) {
                $stats['skipped']++;

                return;
            }

            $supplier->forceFill(['source' => $source])->saveQuietly();
            $stats[$source]++;
        });

        return $stats;
    }

    /**
     * Tentukan source dari nama, lalu dari riwayat transaksi. Null bila ambigu.
     */
    public static function detectSource(self $supplier): ?string
    {
        $name = strtoupper(trim((string) $supplier->supplier_name));

        if (str_starts_with($name, 'PT.') || str_starts_with($name, 'PT ') || $name === 'PT') {
            return self::SOURCE_LOCAL;
        }

        $history = static::historySources((int) $supplier->id);

        return count($history) === 1 ? $history[0] : null;
    }

    /**
     * Source yang pernah tercatat pada transaksi supplier ini.
     *
     * @return list<string>
     */
    public static function historySources(int $supplierId): array
    {
        $sources = [];

        $hasImportPo = DB::table('purchase_orders')
            ->where('supplier_id', $supplierId)
            ->whereNull('deleted_at')
            ->exists();

        $hasLocal = DB::table('incoming_arrivals')
            ->where('supplier_id', $supplierId)
            ->where('is_local', true)
            ->whereNull('deleted_at')
            ->exists();

        $hasImportArrival = DB::table('incoming_arrivals')
            ->where('supplier_id', $supplierId)
            ->where('is_local', false)
            ->whereNull('deleted_at')
            ->exists();

        if ($hasLocal) {
            $sources[] = self::SOURCE_LOCAL;
        }

        if ($hasImportPo || $hasImportArrival) {
            $sources[] = self::SOURCE_IMPORT;
        }

        return $sources;
    }
}
