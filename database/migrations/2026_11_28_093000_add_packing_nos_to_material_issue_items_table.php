<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_issue_items', function (Blueprint $table) {
            if (! Schema::hasColumn('material_issue_items', 'packing_nos')) {
                $table->json('packing_nos')->nullable()->after('tag');
            }
        });
    }

    public function down(): void
    {
        Schema::table('material_issue_items', function (Blueprint $table) {
            if (Schema::hasColumn('material_issue_items', 'packing_nos')) {
                $table->dropColumn('packing_nos');
            }
        });
    }
};
