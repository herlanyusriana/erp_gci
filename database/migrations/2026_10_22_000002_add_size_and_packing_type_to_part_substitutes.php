<?php

use App\Models\PartSubstitute;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_substitutes', function (Blueprint $table) {
            $table->string('size')->nullable()->after('material_group');
            $table->string('packing_type', 20)->nullable()->after('size');
        });

        PartSubstitute::backfillPackingType();
    }

    public function down(): void
    {
        Schema::table('part_substitutes', function (Blueprint $table) {
            $table->dropColumn(['size', 'packing_type']);
        });
    }
};
