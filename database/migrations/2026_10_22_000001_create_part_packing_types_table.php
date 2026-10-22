<?php

use App\Models\PartPackingType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('part_packing_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->constrained('parts')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('origin', 20)->default(PartPackingType::ORIGIN_MANUAL);
            $table->timestamps();
            $table->unique(['part_id', 'code']);
        });

        // Katalog awal dari packing type yang pernah tercatat pada Arrival.
        PartPackingType::syncFromArrivalHistory();
    }

    public function down(): void
    {
        Schema::dropIfExists('part_packing_types');
    }
};
