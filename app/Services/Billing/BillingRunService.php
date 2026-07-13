<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Area;
use App\Models\BillingRun;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ServiceType;
use App\Models\Tariff;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Generates the invoices for a billing run: for every active customer, charge
 * each service they subscribe to using the tariff configured for their suburb,
 * in the customer's own currency. Tax is applied per the municipality's rate to
 * taxable services. Totals are accumulated per currency so a multi-currency
 * municipality can see exactly how much it is billing in each.
 *
 * Re-running a draft run is safe: existing invoices are cleared first.
 */
final class BillingRunService
{
    /**
     * Dry-run the billing for a run WITHOUT persisting anything. Powers the
     * pre-billing report so a run can be checked before it is processed.
     *
     * @return array{
     *     invoice_count:int,
     *     currency_totals:array<string,float>,
     *     invoices:list<array{customer_id:int,account_number:string,customer_name:string,currency:string,subtotal:float,tax_total:float,total:float,lines:list<array<string,mixed>>}>
     * }
     */
    public function preview(BillingRun $run): array
    {
        return $this->calculate($run);
    }

    /**
     * Calculate and persist all invoices for the run, then mark it completed.
     *
     * @return array{invoice_count:int, currency_totals:array<string,float>}
     */
    public function generate(BillingRun $run): array
    {
        $municipality = $run->municipality;
        $period = $run->period_month;

        return DB::transaction(function () use ($run, $municipality, $period): array {
            // Clear any prior invoices for an idempotent re-run.
            $run->invoices()->each(fn (Invoice $i) => $i->delete());

            $calc = $this->calculate($run);

            // Continue numbering above the municipality's highest existing invoice
            // number so runs in the same period never collide (computed after the
            // re-run clear above, so this run's old numbers are free to reuse).
            $sequence = $this->highestInvoiceSequence($municipality->id);

            foreach ($calc['invoices'] as $projected) {
                $sequence++;

                $invoice = $run->invoices()->create([
                    'municipality_id' => $municipality->id,
                    'customer_id' => $projected['customer_id'],
                    'invoice_number' => $this->invoiceNumber($period, $sequence),
                    'period_month' => $period,
                    'currency' => $projected['currency'],
                    'subtotal' => $projected['subtotal'],
                    'tax_total' => $projected['tax_total'],
                    'total' => $projected['total'],
                    'status' => 'issued',
                    'issued_at' => now(),
                ]);

                $invoice->lines()->createMany($projected['lines']);
            }

            $run->forceFill([
                'status' => 'completed',
                'invoice_count' => $calc['invoice_count'],
                'currency_totals' => $calc['currency_totals'],
                'run_at' => now(),
            ])->save();

            return ['invoice_count' => $calc['invoice_count'], 'currency_totals' => $calc['currency_totals']];
        });
    }

    /**
     * The shared billing computation: applies the run's scope, frequency and tax
     * to produce the projected invoices and per-currency totals. No DB writes.
     *
     * @return array{invoice_count:int, currency_totals:array<string,float>, invoices:list<array<string,mixed>>}
     */
    private function calculate(BillingRun $run): array
    {
        $taxRate = (float) $run->municipality->tax_rate;

        $customers = $this->scopedCustomers($run);

        // Pre-index active tariffs by area|service|currency for fast lookup.
        $tariffs = Tariff::where('active', true)->get()
            ->keyBy(fn (Tariff $t) => $this->tariffKey($t->area_id, $t->service_id, $t->currency));

        // Optional service filter (empty/null = all services) and frequency scaling.
        $serviceIds = $this->serviceIdFilter($run);
        $multiplier = $run->frequencyMultiplier();

        $currencyTotals = [];
        $invoices = [];

        foreach ($customers as $customer) {
            $lines = [];
            $subtotal = Money::zero($customer->currency);
            $taxTotal = Money::zero($customer->currency);

            foreach ($customer->services as $service) {
                $type = $service->serviceType;
                if (! $service->active || $type === null || ! $type->active) {
                    continue;
                }

                if ($serviceIds !== null && ! in_array($service->id, $serviceIds, true)) {
                    continue; // Not in this run's selected services.
                }

                $tariff = $tariffs->get($this->tariffKey($customer->area_id, $service->id, $customer->currency));
                if ($tariff === null) {
                    continue; // No tariff for this suburb/service in the customer's currency.
                }

                [$quantity, $unitAmount, $amount] = $this->lineAmount($type, $tariff, $customer);

                // Scale by the run's frequency: a quarterly run raises 3 months' charge.
                if ($multiplier !== 1.0) {
                    $amount = $amount->multipliedBy((string) $multiplier, RoundingMode::HALF_UP);
                    $quantity *= $multiplier;
                }

                if ($amount->isZero()) {
                    continue;
                }

                $tax = $service->taxable
                    ? $amount->multipliedBy((string) $taxRate, RoundingMode::HALF_UP)
                    : Money::zero($customer->currency);

                $subtotal = $subtotal->plus($amount);
                $taxTotal = $taxTotal->plus($tax);

                $lines[] = [
                    'service_id' => $service->id,
                    'tr_code' => $tariff->tr_code,
                    'description' => $service->displayName().' — '.$customer->area->name,
                    'quantity' => $quantity,
                    'unit_amount' => $unitAmount,
                    'amount' => $amount->getAmount()->toFloat(),
                    'tax_amount' => $tax->getAmount()->toFloat(),
                ];
            }

            if ($lines === []) {
                continue; // Nothing to bill this customer this period.
            }

            $total = $subtotal->plus($taxTotal);

            $invoices[] = [
                'customer_id' => $customer->id,
                'account_number' => $customer->account_number,
                'customer_name' => $customer->name,
                'currency' => $customer->currency,
                'subtotal' => $subtotal->getAmount()->toFloat(),
                'tax_total' => $taxTotal->getAmount()->toFloat(),
                'total' => $total->getAmount()->toFloat(),
                'lines' => $lines,
            ];

            $currencyTotals[$customer->currency] =
                ($currencyTotals[$customer->currency] ?? 0) + $total->getAmount()->toFloat();
        }

        return [
            'invoice_count' => count($invoices),
            'currency_totals' => $currencyTotals,
            'invoices' => $invoices,
        ];
    }

    /**
     * @return array{0: float, 1: float, 2: Money} [quantity, unitAmount, lineAmount]
     */
    private function lineAmount(ServiceType $type, Tariff $tariff, Customer $customer): array
    {
        $rate = (string) $tariff->rate;

        return match ($type->billing_basis) {
            ServiceType::BASIS_PER_PROPERTY_VALUE => [
                1.0,
                (float) $rate,
                Money::of((string) ($customer->property_value ?? 0), $customer->currency)
                    ->multipliedBy($rate, RoundingMode::HALF_UP),
            ],
            // Metering deferred: per-unit currently bills a single unit.
            ServiceType::BASIS_PER_UNIT => [
                1.0,
                (float) $rate,
                Money::of($rate, $customer->currency, roundingMode: RoundingMode::HALF_UP),
            ],
            default => [
                1.0,
                (float) $rate,
                Money::of($rate, $customer->currency, roundingMode: RoundingMode::HALF_UP),
            ],
        };
    }

    /**
     * Active customers in scope for the run: optionally limited to an account
     * range and/or a range of suburbs (ordered by name, inclusive).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Customer>
     */
    private function scopedCustomers(BillingRun $run): \Illuminate\Database\Eloquent\Collection
    {
        $query = Customer::with(['services.serviceType', 'area'])
            ->where('active', true);

        if ($run->account_from !== null) {
            $query->where('account_number', '>=', $run->account_from);
        }
        if ($run->account_to !== null) {
            $query->where('account_number', '<=', $run->account_to);
        }

        $areaIds = $this->locationRangeAreaIds($run);
        if ($areaIds !== null) {
            $query->whereIn('area_id', $areaIds);
        }

        return $query->get();
    }

    /**
     * The billing-level suburbs falling within the run's from/to location range
     * (ordered by name). Returns null when no location range is set.
     *
     * @return list<int>|null
     */
    private function locationRangeAreaIds(BillingRun $run): ?array
    {
        if ($run->area_from_id === null && $run->area_to_id === null) {
            return null;
        }

        $ordered = Area::billingLevel()->orderBy('name')->pluck('id')->values();

        $from = $run->area_from_id !== null ? $ordered->search($run->area_from_id) : 0;
        $to = $run->area_to_id !== null ? $ordered->search($run->area_to_id) : $ordered->count() - 1;

        if ($from === false || $to === false || $from > $to) {
            return []; // Invalid range bills nobody rather than everybody.
        }

        return $ordered->slice($from, $to - $from + 1)->values()->all();
    }

    /**
     * The service ids this run is limited to, or null for "all services".
     *
     * @return list<int>|null
     */
    private function serviceIdFilter(BillingRun $run): ?array
    {
        $ids = array_map('intval', $run->service_ids ?? []);

        return $ids === [] ? null : $ids;
    }

    private function tariffKey(int $areaId, int $serviceId, string $currency): string
    {
        return "{$areaId}|{$serviceId}|{$currency}";
    }

    private function invoiceNumber(\Illuminate\Support\Carbon $period, int $sequence): string
    {
        // Figures only: billing period (YYYYMM) followed by a zero-padded sequence,
        // e.g. "202607-00001". No municipality name/code prefix.
        return sprintf('%s-%05d', $period->format('Ym'), $sequence);
    }

    /**
     * The largest trailing sequence number across the municipality's existing
     * invoice numbers (the part after the last "-"). Returns 0 when none exist.
     * DB-agnostic: parses in PHP rather than relying on SQL string functions.
     */
    private function highestInvoiceSequence(int $municipalityId): int
    {
        return (int) Invoice::where('municipality_id', $municipalityId)
            ->pluck('invoice_number')
            ->map(fn (string $number) => (int) substr((string) strrchr($number, '-'), 1))
            ->max();
    }
}
