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

    public const TYPE_IMPORT = 'import';

    public const TYPE_LOCAL = 'local';

    public const TYPE_OSP_GCI = 'osp_gci';

    public const TYPE_OSP_CUSTOMER = 'osp_customer';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_IMPORT,
        self::TYPE_LOCAL,
        self::TYPE_OSP_GCI,
        self::TYPE_OSP_CUSTOMER,
    ];

    /** @var list<string> */
    public const OSP_TYPES = [
        self::TYPE_OSP_GCI,
        self::TYPE_OSP_CUSTOMER,
    ];

    protected $fillable = [
        'arrival_no', 'transaction_no', 'po_no', 'invoice_no', 'delivery_note_no',
        'contract_no', 'reference_no', 'invoice_date',
        'supplier_id', 'subcontractor_id', 'customer_id', 'purchase_order_id',
        'trucking_company_id', 'is_local', 'arrival_type',
        'vessel', 'etd', 'eta', 'eta_gci',
        'bill_of_lading', 'pen_no', 'pen_date', 'aju_no',
        'bill_of_lading_file', 'delivery_note_file', 'invoice_file', 'packing_list_file',
        'price_term', 'hs_code', 'port_of_loading', 'country', 'currency',
        'notes', 'remarks', 'status', 'iqc_status', 'iqc_passed_at', 'iqc_passed_by',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'etd' => 'date',
        'eta' => 'date',
        'eta_gci' => 'date',
        'pen_date' => 'date',
        'iqc_passed_at' => 'datetime',
        'is_local' => 'boolean',
    ];

    public function iqcInspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'iqc_passed_by');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function subcontractor(): BelongsTo
    {
        return $this->belongsTo(Subcontractor::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function resolvedArrivalType(): string
    {
        if (in_array($this->arrival_type, self::TYPES, true)) {
            return $this->arrival_type;
        }

        return $this->is_local ? self::TYPE_LOCAL : self::TYPE_IMPORT;
    }

    public function isOsp(): bool
    {
        return in_array($this->resolvedArrivalType(), self::OSP_TYPES, true);
    }

    public function senderEntity(): Supplier|Subcontractor|Customer|null
    {
        return match ($this->resolvedArrivalType()) {
            self::TYPE_OSP_GCI => $this->subcontractor,
            self::TYPE_OSP_CUSTOMER => $this->customer,
            default => $this->supplier,
        };
    }

    public function senderName(): ?string
    {
        return match ($this->resolvedArrivalType()) {
            self::TYPE_OSP_GCI => $this->subcontractor?->subcontractor_name,
            self::TYPE_OSP_CUSTOMER => $this->customer?->customer_name,
            default => $this->supplier?->supplier_name,
        };
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
