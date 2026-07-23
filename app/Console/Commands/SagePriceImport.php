<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sage\SagePriceImportService;
use Illuminate\Console\Command;
use Throwable;

class SagePriceImport extends Command
{
    protected $signature = 'sage:import-prices {--dry-run : Resolve and report prices without writing anything}';

    protected $description = 'Price every ratepayer service from the Sage billables (inventory price list), using each debtor\'s client class to pick the rate variant';

    public function handle(SagePriceImportService $service): int
    {
        $this->info('Pricing ratepayer services from the Sage billables ('.config('database.connections.sage.database').') …');

        try {
            $result = $service->run(dryRun: (bool) $this->option('dry-run'));
        } catch (Throwable $e) {
            $this->error('Import failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(($result['dry_run'] ? '[DRY RUN] Would price' : 'Priced')." into: {$result['municipality']}");
        $this->table(
            ['Service token', 'Cadence', 'Accounts', 'Priced', 'Unpriced', 'Min', 'Max', 'Avg'],
            collect($result['lines'])->map(fn ($l) => [
                $l['token'],
                $l['frequency'],
                number_format($l['subs']),
                number_format($l['priced']),
                number_format($l['unpriced']),
                $l['min'] !== null ? number_format($l['min'], 2) : '—',
                $l['max'] !== null ? number_format($l['max'], 2) : '—',
                $l['avg'] !== null ? number_format($l['avg'], 2) : '—',
            ])->all(),
        );

        if ($result['unmatched'] !== []) {
            $this->newLine();
            $this->warn('Client classes with no confidently-matched billable price (fix the price in Sage, or the class):');
            $this->table(
                ['Class', 'Description', 'Accounts'],
                collect($result['unmatched'])->take(20)->map(fn ($u) => [$u['code'], $u['desc'], number_format($u['clients'])])->all(),
            );
            if (count($result['unmatched']) > 20) {
                $this->line('  … and '.(count($result['unmatched']) - 20).' more.');
            }
        }

        foreach ($result['warnings'] as $warning) {
            $this->warn('• '.$warning);
        }

        $this->newLine();
        $this->info('Done.'.($result['dry_run'] ? ' (Nothing was written — run without --dry-run to apply.)' : ''));

        return self::SUCCESS;
    }
}
