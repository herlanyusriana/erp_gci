<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class IncomingArrival extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'arrival_no', 'transaction_no', 'po_no', 'invoice_no', 'invoice_date',
        'supplier_id', 'purchase_order_id', 'trucking_company_id', 'is_local',
        'vessel', 'etd', 'eta', 'eta_gci',
        'bill_of_lading', 'pen_no', 'pen_date', 'aju_no',
        'bill_of_lading_file', 'delivery_note_file', 'invoice_file', 'packing_list_file',
        'price_term', 'hs_code', 'port_of_loading', 'country', 'currency',
        'notes', 'status', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'etd' => 'date',
        'eta' => 'date',
        'eta_gci' => 'date',
        'pen_date' => 'date',
        'is_local' => 'boolean',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function trucking(): BelongsTo
    {
        return $this->belongsTo(TruckingCompany::class, 'trucking_company_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(IncomingArrivalItem::class, 'arrival_id');
    }

    public function containers(): HasMany
    {
        return $this->hasMany(IncomingArrivalContainer::class, 'arrival_id');
    }

    public static function generateArrivalNo(string $prefix = 'ARV'): string
    {
        $prefix = $prefix.'-'.Carbon::now()->format('ym');
        // withTrashed(): arrival_no UNIQUE juga menghitung baris ter-soft-delete.
        $last = static::withTrashed()
            ->where('arrival_no', 'like', $prefix.'%')
            ->orderByDesc('arrival_no')
            ->value('arrival_no');
        $seq = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    public static function generateTransactionNo(string $date): string
    {
        $prefix = 'SO'.Carbon::parse($date)->format('ymd');
        // withTrashed(): transaction_no UNIQUE juga menghitung baris ter-soft-delete.
        $last = static::withTrashed()
            ->where('transaction_no', 'like', $prefix.'%')
            ->orderByDesc('transaction_no')
            ->value('transaction_no');
        $seq = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;

        return $prefix.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }
}
