<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Sage\SagePropertyWriter;
use Illuminate\Console\Command;

class SagePushProperty extends Command
{
    protected $signature = 'sage:push-property {customer : O-Billing property (customer) id or account number}';

    protected $description = 'Create an O-Billing property (and its owner if new) in the Sage database';

    public function handle(SagePropertyWriter $writer): int
    {
        $arg = (string) $this->argument('customer');
        $customer = Customer::with(['area', 'services.serviceType'])
            ->where('id', $arg)->orWhere('account_number', $arg)->first();

        if ($customer === null) {
            $this->error("Property '{$arg}' not found.");

            return self::FAILURE;
        }

        $this->info("Sending property {$customer->account_number} — {$customer->name} to Sage …");

        $result = $writer->pushProperty($customer);

        if (! ($result['ok'] ?? false)) {
            $this->error($result['error'] ?? 'Push failed.');

            return self::FAILURE;
        }

        $this->info("Created in: {$result['database']}");

        if (($result['mode'] ?? null) === 'ledger') {
            // No property module in this company — the property is its debtor accounts.
            foreach ($result['created'] as $account) {
                $this->line("  • debtor account {$account}");
            }
            foreach ($result['existing'] as $account) {
                $this->line("  • {$account} already existed (skipped)");
            }
            foreach ($result['unmapped'] as $service) {
                $this->warn("  • no Sage account type for service '{$service}' — no account created for it.");
            }
        } else {
            $this->line("  • property #{$result['property_id']} (erf {$result['erf']})");
            $this->line('  • owner debtor #'.$result['owner_dclink'].' ('.($result['owner_created'] ? 'newly created' : 'linked to existing').')');
            $this->line('  • '.$result['services'].' billable service(s) linked');
            if (! $result['area_linked']) {
                $this->warn('  • no Sage area was linked (the O-Billing suburb is not from Sage) — set it in Sage if needed.');
            }
        }

        $this->newLine();
        $this->info('Done. The property now appears in Sage.');

        return self::SUCCESS;
    }
}
