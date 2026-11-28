<?php

declare(strict_types=1);

use App\Support\ReinforceSharedBlankSetup;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ReinforceSharedBlankSetup::apply();
    }

    public function down(): void
    {
        // Konversi BOM bersifat transformasi struktur master; tidak dibalikkan otomatis.
    }
};
