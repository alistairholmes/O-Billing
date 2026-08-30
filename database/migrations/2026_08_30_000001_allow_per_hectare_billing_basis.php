<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `billing_basis` was declared with enum(), which compiles to a CHECK
 * constraint listing the three original bases — so `per_hectare` cannot be
 * stored until that constraint makes room for it. The column becomes a plain
 * string; the allowed set is ServiceType::billingBases(), which the panel's
 * Select already binds to, so the validation moves up rather than disappearing.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Postgres keeps the CHECK as a separate object that outlives a column
        // redefinition, so it has to be dropped by name. SQLite rebuilds the
        // whole table on change() and loses it for free.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE service_types DROP CONSTRAINT IF EXISTS service_types_billing_basis_check');
        }

        Schema::table('service_types', function (Blueprint $table): void {
            $table->string('billing_basis', 50)->default('flat')->change();
        });
    }

    public function down(): void
    {
        // Any per_hectare rows would violate the restored constraint, so retire
        // them to the closest safe basis first rather than failing the rollback.
        DB::table('service_types')->where('billing_basis', 'per_hectare')->update(['billing_basis' => 'flat']);

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE service_types ADD CONSTRAINT service_types_billing_basis_check CHECK (billing_basis IN ('flat', 'per_property_value', 'per_unit'))");

            return;
        }

        Schema::table('service_types', function (Blueprint $table): void {
            $table->enum('billing_basis', ['flat', 'per_property_value', 'per_unit'])->default('flat')->change();
        });
    }
};
