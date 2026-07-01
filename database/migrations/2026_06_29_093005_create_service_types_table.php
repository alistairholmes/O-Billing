<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->string('name');                 // Property Rates, Refuse Removal, Sewerage
            $table->string('code')->nullable();
            // flat = fixed monthly charge; per_property_value = rate x property value;
            // per_unit = unit price x quantity (water metering deferred, but supported)
            $table->enum('billing_basis', ['flat', 'per_property_value', 'per_unit'])->default('flat');
            $table->string('unit_label')->nullable(); // e.g. "kL", "bin"
            // Taxability lives on the individual service, not the group.
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_types');
    }
};
