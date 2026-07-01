<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->string('run_number')->nullable();
            $table->date('period_month');          // first day of the billed period
            // How much of the monthly charge to raise: weekly ~1/4, quarterly x3, annually x12.
            $table->enum('frequency', ['weekly', 'monthly', 'quarterly', 'annually'])->default('monthly');
            $table->string('description')->nullable();
            $table->enum('status', ['draft', 'completed'])->default('draft');

            // Optional scope. NULL/empty means "everything".
            $table->json('service_ids')->nullable();           // only these services; empty = all
            $table->string('account_from')->nullable();        // account-number range
            $table->string('account_to')->nullable();
            $table->foreignId('area_from_id')->nullable()->constrained('areas')->nullOnDelete(); // suburb range
            $table->foreignId('area_to_id')->nullable()->constrained('areas')->nullOnDelete();

            $table->unsignedInteger('invoice_count')->default(0);
            // Totals per currency (multi-currency runs): {"ZAR": 12345.67, "USD": 890.00}
            $table->json('currency_totals')->nullable();
            $table->timestamp('run_at')->nullable();
            $table->timestamps();
            $table->unique(['municipality_id', 'run_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_runs');
    }
};
