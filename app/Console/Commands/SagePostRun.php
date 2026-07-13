<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BillingRun;
use App\Services\Sage\SageBillingRunPoster;
use App\Support\Tenancy\CurrentMunicipality;
use Illuminate\Console\Command;
use Throwable;

class SagePostRun extends Command
{
    protected $signature = 'sage:post-run
        {run : The O-Billing billing run number (e.g. BR-202607-0001)}
        {--write : Actually stage the batch in Sage (default is a dry run)}
        {--force : Stage even if a batch for this run already exists in Sage}';

    protected $description = 'Stage a completed billing run in Sage as an unposted debtors batch (a Sage operator reviews and posts it)';

    public function handle(SageBillingRunPoster $poster): int
    {
        $runNumber = (string) $this->argument('run');
        $run = BillingRun::withoutGlobalScopes()->where('run_number', $runNumber)->first();
        if ($run === null) {
            $this->error("Billing run {$runNumber} not found.");

            return self::FAILURE;
        }
        if ($run->status !== 'completed') {
            $this->error("Billing run {$runNumber} is '{$run->status}' — only completed runs can be posted.");

            return self::FAILURE;
        }

        $write = (bool) $this->option('write');

        try {
            $result = app(CurrentMunicipality::class)->runFor(
                $run->municipality_id,
                fn () => $write ? $poster->post($run, force: (bool) $this->option('force')) : $poster->preview($run),
            );
        } catch (Throwable $e) {
            $this->error('Failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(($write ? 'Staging into' : 'DRY RUN against').": {$result['database']}");
        $this->line("Batch {$result['batch_no']} (ref {$result['batch_ref']}) · tr code {$result['tr_code']} · tx date {$result['tx_date']} · rate {$result['exchange_rate']} ZWG/USD");
        $this->newLine();

        $rows = [];
        $totExcl = $totTax = $totIncl = 0.0;
        foreach ($result['by_token'] as $token => $t) {
            $rows[] = [
                $token,
                number_format($t['lines']),
                number_format($t['usd_excl'], 2),
                number_format($t['usd_tax'], 2),
                number_format($t['usd_incl'], 2),
                $t['contra'] !== null ? '#'.$t['contra'] : '(operator to fill)',
            ];
            $totExcl += $t['usd_excl'];
            $totTax += $t['usd_tax'];
            $totIncl += $t['usd_incl'];
        }
        $rows[] = ['TOTAL', number_format(count($result['lines'])), number_format($totExcl, 2), number_format($totTax, 2), number_format($totIncl, 2), ''];
        $this->table(['Service', 'Lines', 'USD excl', 'USD tax', 'USD incl', 'Revenue GL'], $rows);

        if ($result['unresolved'] !== []) {
            $this->warn('Unresolved ('.count($result['unresolved']).'):');
            foreach (array_slice($result['unresolved'], 0, 10) as $u) {
                $this->warn('  • '.$u);
            }
            if (count($result['unresolved']) > 10) {
                $this->warn('  … and '.(count($result['unresolved']) - 10).' more');
            }
        }
        if ($result['previously_posted']) {
            $this->warn("A batch with ref {$result['batch_ref']} was ALREADY POSTED in Sage previously — staging again would double-bill once posted.");
        }
        if ($result['already_staged']) {
            $this->warn("An unposted batch with ref {$result['batch_ref']} already exists in Sage.");
        }

        if (isset($result['error'])) {
            $this->newLine();
            $this->error($result['error']);

            return self::FAILURE;
        }

        $this->newLine();
        if (! $write) {
            $this->info('Dry run only — NOTHING was written to Sage. Re-run with --write to stage the batch.');
        } else {
            $this->info("Staged batch #{$result['batch_id']} ({$result['staged']} lines) — UNPOSTED.");
            $this->line('Next, in Sage Evolution: Debtors → Transactions → Batch Entry → open batch '
                .$result['batch_no'].' → review the lines (fill any missing GL contra) → Validate → Post.');
            $this->line('Posting in Sage writes the double entry (debtor ↔ revenue ↔ VAT), customer statements, and the trial balance.');
        }

        return self::SUCCESS;
    }
}
