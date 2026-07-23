<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Area;
use App\Models\BillingRun;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceType;
use App\Models\Tariff;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use LogicException;

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
     * Guarded against overcharging: refuses to run when another completed run
     * already bills an overlapping scope for the same period (or was generated
     * today), unless $force is passed. Concurrent generates for the same
     * municipality are serialised by a row lock, so double-clicks and the
     * scheduler can never both bill.
     *
     * @return array{invoice_count:int, currency_totals:array<string,float>}
     *
     * @throws DuplicateBillingRunException when the run would double-bill
     * @throws LogicException when the run is reversed or already in Sage
     */
    public function generate(BillingRun $run, bool $force = false): array
    {
        $municipality = $run->municipality;

        return DB::transaction(function () use ($run, $municipality, $force): array {
            // Serialise per municipality: the row lock is held until commit, so
            // two simultaneous generates cannot both pass the guards below.
            DB::table('municipalities')->where('id', $municipality->id)->lockForUpdate()->first();
            $run->refresh();
            $period = $run->period_month;

            if ($run->isReversed()) {
                throw new LogicException(
                    "Run {$run->run_number} was reversed; create a new run instead of re-running it."
                );
            }
            if ($run->isInSage()) {
                throw new LogicException(
                    "Run {$run->run_number} has already been sent to Sage; re-running it would duplicate charges."
                );
            }
            if (! $force) {
                $conflicts = $this->conflictingRuns($run);
                if ($conflicts->isNotEmpty()) {
                    throw new DuplicateBillingRunException($run, $conflicts);
                }
            }

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
     * Reverse a completed run made by mistake: delete every invoice it
     * generated so customers are not charged, and keep the run (with its counts
     * and totals) marked "reversed" as an audit record. A run that has been
     * queued or posted to Sage cannot be reversed here — the ledger entries
     * already exist and must be corrected in Sage.
     *
     * @throws LogicException when the run is not completed or already in Sage
     */
    public function reverse(BillingRun $run, ?string $reason = null): void
    {
        DB::transaction(function () use ($run, $reason): void {
            // Same per-municipality lock as generate(), so a reversal can never
            // interleave with a generate or a second reversal.
            DB::table('municipalities')->where('id', $run->municipality_id)->lockForUpdate()->first();
            $run->refresh();

            if (! $run->isCompleted()) {
                throw new LogicException(
                    "Only a completed run can be reversed; {$run->run_number} is {$run->status}."
                );
            }
            if ($run->isInSage()) {
                throw new LogicException(
                    "Run {$run->run_number} has already been sent to Sage and cannot be reversed here. "
                    .'Correct it in Sage with a credit note.'
                );
            }

            $run->invoices()->each(fn (Invoice $i) => $i->delete());

            $run->forceFill([
                'status' => 'reversed',
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ])->save();
        });
    }

    /**
     * Completed runs that would bill some customer for the same service twice
     * if $run were generated: same billing month (or generated today) with an
     * overlapping scope. Cadence-aware — a monthly and an annual run in the
     * same period only conflict through services billable by both.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, BillingRun>
     */
    public function conflictingRuns(BillingRun $run, ?int $excludeId = null): \Illuminate\Database\Eloquent\Collection
    {
        $excludeId ??= $run->id;
        $month = $run->period_month->copy()->startOfMonth();

        return BillingRun::query()
            ->where('status', 'completed')
            ->when($excludeId !== null, fn ($q) => $q->whereKeyNot($excludeId))
            ->where(function ($q) use ($month): void {
                $q->whereBetween('period_month', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
                    ->orWhereDate('run_at', now()->toDateString());
            })
            ->get()
            ->filter(fn (BillingRun $other) => $this->wouldDoubleBill($run, $other))
            ->values();
    }

    /** Whether the two runs' scopes overlap on accounts, locations AND services. */
    private function wouldDoubleBill(BillingRun $a, BillingRun $b): bool
    {
        return $this->accountRangesOverlap($a, $b)
            && $this->areaRangesOverlap($a, $b)
            && $this->doubleBilledServiceExists($a, $b);
    }

    private function accountRangesOverlap(BillingRun $a, BillingRun $b): bool
    {
        // Ranges are string-compared, matching scopedCustomers(); null = open end.
        if ($a->account_to !== null && $b->account_from !== null && strcmp($a->account_to, $b->account_from) < 0) {
            return false;
        }
        if ($b->account_to !== null && $a->account_from !== null && strcmp($b->account_to, $a->account_from) < 0) {
            return false;
        }

        return true;
    }

    private function areaRangesOverlap(BillingRun $a, BillingRun $b): bool
    {
        $aIds = $this->locationRangeAreaIds($a);
        $bIds = $this->locationRangeAreaIds($b);

        if ($aIds === null || $bIds === null) {
            return true; // one of them covers all locations
        }

        return array_intersect($aIds, $bIds) !== [];
    }

    /**
     * Whether at least one active service falls in both runs' service scopes AND
     * would actually be billed by both. A service with no cadence is billed by
     * every run; a cadenced service only by runs of its own frequency, so it is
     * double-billed only when both runs share that frequency.
     */
    private function doubleBilledServiceExists(BillingRun $a, BillingRun $b): bool
    {
        $aIds = $this->serviceIdFilter($a);
        $bIds = $this->serviceIdFilter($b);

        return Service::with('serviceType')
            ->where('active', true)
            ->when($aIds !== null, fn ($q) => $q->whereIn('id', $aIds))
            ->when($bIds !== null, fn ($q) => $q->whereIn('id', $bIds))
            ->get()
            ->contains(function (Service $service) use ($a, $b): bool {
                $type = $service->serviceType;
                if ($type === null || ! $type->active) {
                    return false;
                }

                $cadence = $type->default_frequency;

                return $cadence === null
                    || ($cadence === $a->frequency && $a->frequency === $b->frequency);
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

        // Optional service filter (empty/null = all services).
        $serviceIds = $this->serviceIdFilter($run);

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

                // Frequency-aware billing. A service with a set cadence is billed
                // only by a run of that same frequency, and its tariff is the charge
                // for that whole period (no scaling). A service with no cadence keeps
                // the legacy behaviour: billed by any run, its monthly rate scaled up
                // by the run's frequency (quarterly = 3 months, annually = 12).
                $frequency = $type->default_frequency;
                if ($frequency !== null) {
                    if ($frequency !== $run->frequency) {
                        continue; // Not this run's cadence.
                    }
                    $multiplier = 1.0;
                } else {
                    $multiplier = $run->frequencyMultiplier();
                }

                // A per-customer charge (imported from the council's own client
                // schedule) overrides the suburb tariff outright.
                $override = $service->pivot->amount;
                if ($override !== null) {
                    $quantity = 1.0;
                    $unitAmount = (float) $override;
                    $amount = Money::of((string) $override, $customer->currency, roundingMode: RoundingMode::HALF_UP);
                    $trCode = $type->code;
                } else {
                    $tariff = $tariffs->get($this->tariffKey($customer->area_id, $service->id, $customer->currency));
                    if ($tariff === null) {
                        continue; // No tariff for this suburb/service in the customer's currency.
                    }

                    [$quantity, $unitAmount, $amount] = $this->lineAmount($type, $tariff, $customer);
                    $trCode = $tariff->tr_code;
                }

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
                    'tr_code' => $trCode,
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
