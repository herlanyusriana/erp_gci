<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Harga jual satu barang jadi untuk satu customer.
 *
 * Dipakai saat picking menghasilkan pengiriman: harga ini disalin ke baris
 * pengiriman (`delivery_items.price`) sebagai snapshot, sehingga perubahan
 * harga di master tidak mengubah dokumen yang sudah terbit.
 */
class CustomerPrice extends Model
{
    protected $fillable = [
        'customer_id', 'part_id', 'price', 'currency', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'price' => 'float',
        'is_active' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }
}
