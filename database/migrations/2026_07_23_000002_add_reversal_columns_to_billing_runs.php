<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A completed run made by mistake can be reversed: its invoices are deleted so
 * customers are not charged, and the run is kept (status "reversed") with the
 * reason as an audit record. Runs already posted to Sage cannot be reversed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->replaceStatusCheck(['draft', 'completed', 'reversed']);

        Schema::table('billing_runs', function (Blueprint $table): void {
            $table->timestamp('reversed_at')->nullable()->after('posted_at');
            $table->text('reversal_reason')->nullable()->after('reversed_at');
        });
    }

    public function down(): void
    {
        $this->replaceStatusCheck(['draft', 'completed']);

        Schema::table('billing_runs', function (Blueprint $table): void {
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });
    }

    /**
     * Widen (or narrow) the allowed status values. On Postgres the enum is a
     * varchar with an auto-named check constraint, which Laravel's enum
     * ->change() cannot alter correctly, so swap the constraint by hand; other
     * drivers (sqlite in tests) rebuild the column via ->change().
     *
     * @param  list<string>  $statuses
     */
    private function replaceStatusCheck(array $statuses): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $quoted = implode(', ', array_map(fn (string $s) => "'{$s}'", $statuses));

            DB::statement('alter table billing_runs drop constraint if exists billing_runs_status_check');
            DB::statement("alter table billing_runs add constraint billing_runs_status_check check (status in ({$quoted}))");

            return;
        }

        Schema::table('billing_runs', function (Blueprint $table) use ($statuses): void {
            $table->enum('status', $statuses)->default('draft')->change();
        });
    }
};
