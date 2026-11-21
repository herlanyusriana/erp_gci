<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_issues', function (Blueprint $table) {
            if (! Schema::hasColumn('material_issues', 'operator_name')) {
                $table->string('operator_name', 120)->nullable()->after('issued_by');
            }
        });

        Schema::table('production_material_receipts', function (Blueprint $table) {
            if (! Schema::hasColumn('production_material_receipts', 'operator_name')) {
                $table->string('operator_name', 120)->nullable()->after('received_by');
            }
        });

        Schema::table('production_results', function (Blueprint $table) {
            if (! Schema::hasColumn('production_results', 'operator_name')) {
                $table->string('operator_name', 120)->nullable()->after('reported_by');
            }
        });

        Schema::table('machine_downtimes', function (Blueprint $table) {
            if (! Schema::hasColumn('machine_downtimes', 'operator_name')) {
                $table->string('operator_name', 120)->nullable()->after('reported_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('material_issues', function (Blueprint $table) {
            if (Schema::hasColumn('material_issues', 'operator_name')) {
                $table->dropColumn('operator_name');
            }
        });

        Schema::table('production_material_receipts', function (Blueprint $table) {
            if (Schema::hasColumn('production_material_receipts', 'operator_name')) {
                $table->dropColumn('operator_name');
            }
        });

        Schema::table('production_results', function (Blueprint $table) {
            if (Schema::hasColumn('production_results', 'operator_name')) {
                $table->dropColumn('operator_name');
            }
        });

        Schema::table('machine_downtimes', function (Blueprint $table) {
            if (Schema::hasColumn('machine_downtimes', 'operator_name')) {
                $table->dropColumn('operator_name');
            }
        });
    }
};
