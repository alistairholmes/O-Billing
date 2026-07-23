<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Area;
use App\Models\AreaType;
use App\Models\BillingRun;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Municipality;
use App\Models\ServiceType;
use App\Models\Tariff;
use App\Services\Billing\BillingRunService;
use App\Services\Billing\DuplicateBillingRunException;
use App\Support\Tenancy\CurrentMunicipality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * The overcharge protections: duplicate runs are blocked, and a mistaken
 * completed run can be reversed (invoices deleted, run kept on record).
 */
class BillingRunSafetyTest extends TestCase
{
    use RefreshDatabase;

    private Municipality $municipality;

    private \App\Models\Service $service;

    /** Bootstraps a municipality with one suburb, one service and two customers. */
    private function setUpBilling(callable $test): void
    {
        $this->municipality = Municipality::create([
            'name' => 'Safety Muni', 'code' => 'SFT', 'base_currency' => 'ZAR',
            'supported_currencies' => ['ZAR'], 'tax_rate' => 0.0, 'tax_label' => 'VAT',
        ]);

        app(CurrentMunicipality::class)->runFor($this->municipality->id, function () use ($test): void {
            $suburbType = AreaType::create([
                'municipality_id' => $this->municipality->id,
                'name' => 'Suburb', 'level' => 1, 'is_billing_level' => true,
            ]);
            $suburb = Area::create([
                'municipality_id' => $this->municipality->id,
                'area_type_id' => $suburbType->id, 'name' => 'Testville',
            ]);
            $refuse = ServiceType::create([
                'municipality_id' => $this->municipality->id, 'name' => 'Refuse', 'code' => 'REFUSE',
                'billing_basis' => ServiceType::BASIS_FLAT, 'active' => true,
            ]);
            $this->service = $refuse->ensureDefaultService(taxable: false);
            Tariff::create([
                'municipality_id' => $this->municipality->id, 'area_id' => $suburb->id,
                'service_id' => $this->service->id, 'rate' => 50, 'currency' => 'ZAR', 'active' => true,
            ]);

            foreach (['A1', 'A2'] as $acc) {
                $c = Customer::create([
                    'municipality_id' => $this->municipality->id, 'area_id' => $suburb->id,
                    'account_number' => $acc, 'name' => "Resident {$acc}", 'type' => 'residential',
                    'currency' => 'ZAR', 'active' => true,
                ]);
                $c->services()->sync([$this->service->id]);
            }

            $test();
        });
    }

    private function makeRun(array $attributes = []): BillingRun
    {
        return BillingRun::create($attributes + [
            'municipality_id' => $this->municipality->id,
            'run_number' => 'BR-'.fake()->unique()->numerify('####'),
            'period_month' => now()->startOfMonth(),
            'frequency' => 'monthly',
        ]);
    }

    public function test_a_second_overlapping_run_for_the_same_period_is_blocked(): void
    {
        $this->setUpBilling(function (): void {
            $billing = app(BillingRunService::class);

            $billing->generate($this->makeRun(['run_number' => 'BR-FIRST']));

            $duplicate = $this->makeRun(['run_number' => 'BR-DUP']);

            try {
                $billing->generate($duplicate);
                $this->fail('Expected DuplicateBillingRunException.');
            } catch (DuplicateBillingRunException $e) {
                $this->assertStringContainsString('BR-FIRST', $e->getMessage());
            }

            // Only the first run's invoices exist — nobody was billed twice.
            $this->assertSame(2, Invoice::count());
            $this->assertSame('draft', $duplicate->fresh()->status);
        });
    }

    public function test_non_overlapping_account_ranges_may_bill_the_same_period(): void
    {
        $this->setUpBilling(function (): void {
            $billing = app(BillingRunService::class);

            $billing->generate($this->makeRun(['account_from' => 'A1', 'account_to' => 'A1']));
            $billing->generate($this->makeRun(['account_from' => 'A2', 'account_to' => 'A2']));

            // Each customer billed exactly once.
            $this->assertSame(2, Invoice::count());
            $this->assertSame(2, Invoice::distinct('customer_id')->count('customer_id'));
        });
    }

    public function test_cadenced_services_allow_runs_of_different_frequencies_in_the_same_period(): void
    {
        $this->setUpBilling(function (): void {
            // Give the service a monthly cadence: an annual run cannot bill it,
            // so a monthly + annual run pair in the same period is legitimate.
            $this->service->serviceType->update(['default_frequency' => 'monthly']);

            $billing = app(BillingRunService::class);

            $billing->generate($this->makeRun(['frequency' => 'monthly']));
            $billing->generate($this->makeRun(['frequency' => 'annually'])); // no conflict, bills nothing

            $this->assertSame(2, Invoice::count());
        });
    }

    public function test_re_running_the_same_run_replaces_rather_than_duplicates(): void
    {
        $this->setUpBilling(function (): void {
            $billing = app(BillingRunService::class);
            $run = $this->makeRun();

            $billing->generate($run);
            $billing->generate($run); // the run itself is excluded from the guard

            $this->assertSame(2, Invoice::count());
        });
    }

    public function test_a_reversed_run_deletes_its_invoices_and_frees_the_period(): void
    {
        $this->setUpBilling(function (): void {
            $billing = app(BillingRunService::class);

            $mistake = $this->makeRun(['run_number' => 'BR-MISTAKE']);
            $billing->generate($mistake);
            $this->assertSame(2, Invoice::count());

            $billing->reverse($mistake, 'Wrong tariff applied');

            $mistake->refresh();
            $this->assertSame('reversed', $mistake->status);
            $this->assertSame('Wrong tariff applied', $mistake->reversal_reason);
            $this->assertNotNull($mistake->reversed_at);
            $this->assertSame(0, Invoice::count());
            // The what-was-reversed record is kept for the audit trail.
            $this->assertSame(2, $mistake->invoice_count);

            // The period is free again for the corrected run.
            $corrected = $this->makeRun(['run_number' => 'BR-FIXED']);
            $billing->generate($corrected);
            $this->assertSame(2, Invoice::count());

            // A reversed run can never be re-run back to life.
            $this->expectException(LogicException::class);
            $billing->generate($mistake);
        });
    }

    public function test_a_run_in_sage_can_be_neither_reversed_nor_re_run(): void
    {
        $this->setUpBilling(function (): void {
            $billing = app(BillingRunService::class);

            $run = $this->makeRun();
            $billing->generate($run);
            $run->forceFill(['posting_status' => 'posted'])->save();

            try {
                $billing->reverse($run, 'Too late');
                $this->fail('Expected LogicException.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('Sage', $e->getMessage());
            }

            try {
                $billing->generate($run);
                $this->fail('Expected LogicException.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('Sage', $e->getMessage());
            }

            // Nothing was deleted or regenerated.
            $this->assertSame(2, Invoice::count());
            $this->assertSame('completed', $run->fresh()->status);
        });
    }
}
