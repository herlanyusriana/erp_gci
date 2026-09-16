<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trucking_companies', function (Blueprint $table) {
            $table->id();
            $table->string('company_code')->unique();
            $table->string('company_name');
            $table->text('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('contact_person')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::table('incoming_arrivals', function (Blueprint $table) {
            $table->foreignId('trucking_company_id')->nullable()
                ->constrained('trucking_companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('incoming_arrivals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trucking_company_id');
        });

        Schema::dropIfExists('trucking_companies');
    }
};
