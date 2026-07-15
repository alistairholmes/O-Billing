<?php

declare(strict_types=1);

namespace App\Services\Sage;

use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pushes an O-Billing invoice back to Sage as PENDING ad-hoc billing: one
 * `_ccg_EB_AdHocBillingBatches` per service and an `_ccg_EB_AdHocBillingEntries`
 * row per line (Calculated = Processed = 0). The charge is only staged — a Sage
 * operator Calculates & Processes the batch, so Sage itself does the ledger
 * posting (GL/AR, audit trail) and the books can never be left unbalanced.
 *
 * Writes go to the `sage_write` connection, which defaults to the NON-PRODUCTION
 * test company (SAGE_WRITE_DATABASE) until it is deliberately pointed at the live
 * database. Property/service links are resolved against that same target so the
 * foreign keys are always valid there.
 *
 * Only companies running the CCG property module have this staging pipeline —
 * check {@see targetsAdHocBilling()} first. Debtors-ledger companies (e.g.
 * Gokwe South) post single invoices via SageBillingRunPoster::postInvoice()
 * instead.
 */
final class SageAdHocBillingWriter
{
    private const CONN = 'sage_write';

    /** Whether the write target has the ad-hoc billing staging tables. */
    public function targetsAdHocBilling(): bool
    {
        static $has = null;

        return $has ??= DB::connection(self::CONN)->getSchemaBuilder()->hasTable('_ccg_EB_AdHocBillingBatches');
    }

    /**
     * @return array{
     *   ok: bool, database: string, error?: string,
     *   batches?: list<array{batch_id:int, service_id:int, entries:int}>,
     *   unresolved?: list<string>, period_id?: int
     * }
     */
    public function pushInvoice(Invoice $invoice): array
    {
        $database = (string) config('database.connections.'.self::CONN.'.database');

        // The Sage property (and its debtor) for this O-Billing account.
        $property = DB::connection(self::CONN)->table('Client as c')
            ->join('_ccg_EB_Properties as p', 'p.OwnerID', '=', 'c.DCLink')
            ->where('c.Account', $invoice->customer->account_number)
            ->select('p.ID as PropertyID')
            ->first();

        if ($property === null) {
            return ['ok' => false, 'database' => $database,
                'error' => "No Sage property found for account {$invoice->customer->account_number} in {$database}."];
        }

        $periodId = $this->resolvePeriod($invoice->period_month);
        if ($periodId === null) {
            return ['ok' => false, 'database' => $database, 'error' => 'Could not resolve a Sage billing period for the invoice month.'];
        }

        // Don't stage the same invoice twice (that would double-bill once processed).
        $reference = 'OB-'.$invoice->invoice_number;
        if (DB::connection(self::CONN)->table('_ccg_EB_AdHocBillingBatches')->where('Reference', $reference)->exists()) {
            return ['ok' => false, 'database' => $database,
                'error' => "Invoice {$invoice->invoice_number} is already staged in {$database}. Process or delete the existing ad-hoc batch in Sage before pushing it again."];
        }

        // Group resolvable lines by Sage service (a batch is per-service).
        $entriesByService = [];
        $unresolved = [];
        foreach ($invoice->lines as $line) {
            $tariffId = $this->tariffId($line->service?->code);
            $propertyService = $tariffId === null ? null : DB::connection(self::CONN)
                ->table('_ccg_EB_PropertyServices')
                ->where('PropertyID', $property->PropertyID)
                ->where('TariffID', $tariffId)
                ->select('ID', 'CustomerID', 'ServiceID')->first();

            if ($propertyService === null) {
                $unresolved[] = $line->description;

                continue;
            }

            $entriesByService[$propertyService->ServiceID][] = [
                'PropertyServiceID' => (int) $propertyService->ID,
                'CustomerID' => (int) $propertyService->CustomerID,
                'Units' => (float) $line->quantity,
            ];
        }

        if ($entriesByService === []) {
            return ['ok' => false, 'database' => $database,
                'error' => 'None of the invoice lines matched a Sage property-service (nothing to push).',
                'unresolved' => $unresolved];
        }

        $batches = [];
        DB::connection(self::CONN)->transaction(function () use (&$batches, $entriesByService, $periodId, $invoice, $reference): void {
            $now = now();
            foreach ($entriesByService as $serviceId => $entries) {
                $batchId = DB::connection(self::CONN)->table('_ccg_EB_AdHocBillingBatches')->insertGetId([
                    'BillingPeriodID' => $periodId,
                    'ServiceID' => (int) $serviceId,
                    'Reference' => $reference,
                    'Description' => 'O-Billing invoice '.$invoice->invoice_number,
                    'Status' => 0, // pending — awaiting Calculate & Process in Sage
                    'UserCreated' => 'O-Billing',
                    'DateCreated' => $now,
                ]);

                foreach ($entries as $e) {
                    DB::connection(self::CONN)->table('_ccg_EB_AdHocBillingEntries')->insert([
                        'PropertyServiceID' => $e['PropertyServiceID'],
                        'CustomerID' => $e['CustomerID'],
                        'Units' => $e['Units'],
                        'Calculated' => false,
                        'Processed' => false,
                        'AdHocBillingBatchID' => $batchId,
                        'PeriodID' => $periodId,
                        'UserCreated' => 'O-Billing',
                        'DateCreated' => $now,
                    ]);
                }

                $batches[] = ['batch_id' => (int) $batchId, 'service_id' => (int) $serviceId, 'entries' => count($entries)];
            }
        });

        return [
            'ok' => true,
            'database' => $database,
            'batches' => $batches,
            'unresolved' => $unresolved,
            'period_id' => $periodId,
        ];
    }

    /** The Sage period whose date falls in the invoice month (else the latest on/before it). */
    private function resolvePeriod(Carbon $month): ?int
    {
        $id = DB::connection(self::CONN)->table('_etblPeriod')
            ->whereYear('dPeriodDate', $month->year)
            ->whereMonth('dPeriodDate', $month->month)
            ->value('idPeriod');

        $id ??= DB::connection(self::CONN)->table('_etblPeriod')
            ->where('dPeriodDate', '<=', $month->copy()->endOfMonth())
            ->max('idPeriod');

        return $id !== null ? (int) $id : null;
    }

    /** Recover the Sage tariff id from an imported service's code ("TRF-123" → 123). */
    private function tariffId(?string $code): ?int
    {
        return ($code !== null && str_starts_with($code, 'TRF-')) ? (int) substr($code, 4) : null;
    }
}
