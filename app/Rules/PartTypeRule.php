<?php

namespace App\Rules;

use App\Models\Part;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PartTypeRule implements ValidationRule
{
    /**
     * @param  array<int, string>  $forbiddenCodes  part_types.code values (lowercase) yang tidak boleh dipilih
     */
    public function __construct(private array $forbiddenCodes) {}

    public static function notFg(): self
    {
        return new self(['fg']);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return;
        }

        $part = Part::with('partType')->find((int) $value);
        if ($part === null) {
            return;
        }

        $code = strtolower((string) $part->partType?->code);
        if (in_array($code, $this->forbiddenCodes, true)) {
            $fail(__('Tipe part tidak diizinkan untuk aksi ini.'));
        }
    }
}
