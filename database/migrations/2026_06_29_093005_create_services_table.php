<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A billable variant of a service type, e.g. Property Rates →
        // High Density / Low Density. Every service type has at least one
        // (a default), so a type without real variants still bills.
        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_type_id')->constrained()->cascadeOnDelete();
            $table->string('name');                 // High Density, Low Density, …
            $table->string('code')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('taxable')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['service_type_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
