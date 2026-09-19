<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\UomCatalog;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Memvalidasi kode satuan terhadap master `uoms` (case-insensitive).
 */
class UomCode implements ValidationRule
{
    public function __construct(private bool $nullable = false) {}

    public static function required(): self
    {
        return new self(false);
    }

    public static function optional(): self
    {
        return new self(true);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if (! $this->nullable) {
                $fail(__('Satuan wajib diisi.'));
            }

            return;
        }

        if (! UomCatalog::exists((string) $value)) {
            $fail(__('Satuan :unit tidak terdaftar di master UOM.', ['unit' => $value]));
        }
    }
}
