<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A recurring billing schedule: automatically creates (and optionally posts) a
 * billing run on a cadence, like Sage's Annuity Billing Configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->boolean('auto_post')->default(false); // queue the post to Sage when it fires

            // Cadence: same set as BillingRun so the created run bills correctly.
            $table->enum('frequency', ['weekly', 'monthly', 'quarterly', 'annually'])->default('monthly');
            // When in the period to fire (month-based cadences); weekly ignores it.
            $table->enum('day_mode', ['last', 'specific'])->default('last');
            $table->unsignedTinyInteger('day_of_month')->nullable(); // 1–28 when day_mode = specific

            $table->date('start_date');
            $table->date('end_date')->nullable();                // null = never terminates
            $table->unsignedInteger('max_occurrences')->nullable(); // null = unlimited
            $table->unsignedInteger('occurrences_count')->default(0);

            $table->timestamp('next_run_at')->nullable();        // null once finished/inactive
            $table->timestamp('last_run_at')->nullable();

            // Scope of the runs this schedule creates (mirrors billing_runs).
            $table->json('service_ids')->nullable();
            $table->string('account_from')->nullable();
            $table->string('account_to')->nullable();
            $table->foreignId('area_from_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->foreignId('area_to_id')->nullable()->constrained('areas')->nullOnDelete();

            $table->timestamps();

            $table->index(['active', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_schedules');
    }
};
