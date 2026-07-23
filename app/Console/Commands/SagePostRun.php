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
        {--write : Actually post the invoices into Sage (default is a dry run)}
        {--force : Post even if some of this run\'s invoices already exist in Sage}';

    protected $description = 'Post a completed billing run into Sage as posted customer invoices (documents + debtor and GL entries)';

    public function handle(SageBillingRunPoster $poster): int
    {
        // Building all the invoice documents + Sage lookup maps in memory
        // exceeds PHP's default 128M; match the worker job's headroom.
        ini_set('memory_limit', (string) env('SAGE_JOB_MEMORY_LIMIT', '2048M'));

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

        $this->info(($write ? 'Posting into' : 'DRY RUN against').": {$result['database']}");
        $this->line("Tr code {$result['tr_code']} · tx date {$result['tx_date']} (period {$result['period_id']}) · rate {$result['exchange_rate']} ZWG/USD · numbering from {$result['next_invoice_number']}");
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
                '#'.$t['revenue_account'],
            ];
            $totExcl += $t['usd_excl'];
            $totTax += $t['usd_tax'];
            $totIncl += $t['usd_incl'];
        }
        $rows[] = ['TOTAL', number_format(count($result['docs'])), number_format($totExcl, 2), number_format($totTax, 2), number_format($totIncl, 2), ''];
        $this->table(['Service', 'Invoices', 'USD excl', 'USD tax', 'USD incl', 'Revenue GL'], $rows);

        if ($result['unresolved'] !== []) {
            $this->warn('Unresolved ('.count($result['unresolved']).'):');
            foreach (array_slice($result['unresolved'], 0, 10) as $u) {
                $this->warn('  • '.$u);
            }
            if (count($result['unresolved']) > 10) {
                $this->warn('  … and '.(count($result['unresolved']) - 10).' more');
            }
        }
        if ($result['already_posted'] > 0) {
            $this->warn("{$result['already_posted']} invoice(s) of this run are ALREADY POSTED in Sage (e.g. {$result['already_posted_example']}).");
        }

        if (isset($result['error'])) {
            $this->newLine();
            $this->error($result['error']);

            return self::FAILURE;
        }

        $this->newLine();
        if (! $write) {
            $this->info('Dry run only — NOTHING was written to Sage. Re-run with --write to post the invoices.');
        } else {
            $this->info("Posted {$result['posted']} Sage invoices ({$result['invoice_from']} … {$result['invoice_to']}).");
            $this->line('The debtor accounts are debited, revenue and VAT credited, and customer statements,');
            $this->line('the trial balance and account enquiries in Sage Evolution reflect the run immediately.');
        }

        return self::SUCCESS;
    }
}
