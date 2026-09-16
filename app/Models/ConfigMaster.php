<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConfigMaster extends Model
{
    use HasFactory;

    protected $fillable = [
        'group', 'key', 'value', 'data_type', 'description', 'is_active',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Read a config value by (group, key) with optional default.
     */
    public static function getValue(string $group, string $key, mixed $default = null): mixed
    {
        $row = static::where('group', $group)
            ->where('key', $key)
            ->where('is_active', true)
            ->first();

        if ($row === null) {
            return $default;
        }

        return match ($row->data_type) {
            'boolean' => filter_var($row->value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $row->value,
            'float', 'decimal' => (float) $row->value,
            'json' => json_decode($row->value, true),
            default => $row->value,
        };
    }
}