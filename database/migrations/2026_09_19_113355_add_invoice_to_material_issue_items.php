<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_issue_items', function (Blueprint $table) {
            $table->string('invoice')->nullable()->after('tag');
            $table->string('supplier')->nullable()->after('invoice');
        });
    }

    public function down(): void
    {
        Schema::table('material_issue_items', function (Blueprint $table) {
            $table->dropColumn(['invoice', 'supplier']);
        });
    }
};
