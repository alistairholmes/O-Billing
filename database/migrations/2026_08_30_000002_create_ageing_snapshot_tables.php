<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Debtor ageing is read from Sage (`PostAR.Outstanding` per transaction), which
 * the cloud app cannot reach — so the worker captures a snapshot and the panel
 * reports off that. Snapshots are kept rather than overwritten: an ageing report
 * is only useful next to the one before it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ageing_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->date('as_at');                       // the date balances were aged against
            $table->string('source_database');           // which Sage company it came from
            $table->unsignedInteger('account_count')->default(0);
            $table->unsignedInteger('transaction_count')->default(0);
            $table->timestamps();
            $table->index(['municipality_id', 'as_at']);
        });

        Schema::create('ageing_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ageing_snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('service_token', 40);         // LEA, REF, LIC … from the account code
            $table->string('service_label');             // the O-Billing service name, when known
            $table->char('currency', 3);
            $table->decimal('current', 18, 2)->default(0);
            $table->decimal('days_30', 18, 2)->default(0);
            $table->decimal('days_60', 18, 2)->default(0);
            $table->decimal('days_90', 18, 2)->default(0);
            $table->decimal('days_120_plus', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->unsignedInteger('account_count')->default(0);
            $table->index(['ageing_snapshot_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ageing_rows');
        Schema::dropIfExists('ageing_snapshots');
    }
};
