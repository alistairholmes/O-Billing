<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete(); // suburb
            $table->string('account_number');
            $table->string('name');
            $table->enum('type', ['residential', 'business', 'government'])->default('residential');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->decimal('property_value', 15, 2)->nullable(); // rateable value for per_property_value rates
            // Optional valuation-roll detail, captured when available.
            $table->decimal('land_size', 15, 2)->nullable();         // area, e.g. m²
            $table->decimal('land_value', 15, 2)->nullable();
            $table->decimal('improvement_value', 15, 2)->nullable();
            $table->char('currency', 3)->default('ZAR');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['municipality_id', 'account_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
