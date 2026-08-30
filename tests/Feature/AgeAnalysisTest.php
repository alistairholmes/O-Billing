<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AgeingRow;
use App\Models\AgeingSnapshot;
use App\Models\Municipality;
use App\Models\User;
use App\Support\Tenancy\CurrentMunicipality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgeAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private function seedSnapshot(Municipality $municipality): AgeingSnapshot
    {
        return app(CurrentMunicipality::class)->runFor($municipality->id, function () use ($municipality): AgeingSnapshot {
            $snapshot = AgeingSnapshot::create([
                'municipality_id' => $municipality->id,
                'as_at' => '2026-06-30',
                'source_database' => 'Test Company',
                'account_count' => 3,
                'transaction_count' => 5,
            ]);

            AgeingRow::create([
                'ageing_snapshot_id' => $snapshot->id,
                'service_token' => 'REF', 'service_label' => 'Refuse Collection', 'currency' => 'USD',
                'current' => 100, 'days_30' => 50, 'days_60' => 0, 'days_90' => 25, 'days_120_plus' => 125,
                'total' => 300, 'account_count' => 2,
            ]);
            AgeingRow::create([
                'ageing_snapshot_id' => $snapshot->id,
                'service_token' => 'LEA', 'service_label' => 'Land Lease', 'currency' => 'USD',
                'current' => 0, 'days_30' => 0, 'days_60' => 0, 'days_90' => 0, 'days_120_plus' => 900,
                'total' => 900, 'account_count' => 1,
            ]);

            return $snapshot;
        });
    }

    private function municipality(): Municipality
    {
        return Municipality::create([
            'name' => 'Ageing Muni', 'code' => 'AGE', 'base_currency' => 'USD',
            'supported_currencies' => ['USD'], 'tax_rate' => 0.0, 'tax_label' => 'VAT',
        ]);
    }

    public function test_it_reports_the_share_of_a_balance_that_is_over_ninety_days(): void
    {
        $municipality = $this->municipality();
        $snapshot = $this->seedSnapshot($municipality);

        app(CurrentMunicipality::class)->runFor($municipality->id, function () use ($snapshot): void {
            $refuse = $snapshot->rows()->where('service_token', 'REF')->first();
            $lease = $snapshot->rows()->where('service_token', 'LEA')->first();

            // 25 + 125 of 300.
            $this->assertSame(0.5, $refuse->overdueShare());
            // Entirely in the oldest bucket.
            $this->assertSame(1.0, $lease->overdueShare());
        });
    }

    public function test_totals_are_grouped_by_currency_so_they_are_never_summed_across_them(): void
    {
        $municipality = $this->municipality();
        $snapshot = $this->seedSnapshot($municipality);

        app(CurrentMunicipality::class)->runFor($municipality->id, function () use ($snapshot): void {
            AgeingRow::create([
                'ageing_snapshot_id' => $snapshot->id,
                'service_token' => 'REF', 'service_label' => 'Refuse Collection', 'currency' => 'ZWG',
                'current' => 0, 'days_30' => 0, 'days_60' => 0, 'days_90' => 0, 'days_120_plus' => 5_000_000,
                'total' => 5_000_000, 'account_count' => 1,
            ]);

            $totals = $snapshot->fresh('rows')->totalsByCurrency();

            $this->assertSame(1200.0, $totals['USD']['total']);
            $this->assertSame(5_000_000.0, $totals['ZWG']['total']);
        });
    }

    public function test_the_pdf_route_renders_and_refuses_another_municipality(): void
    {
        $municipality = $this->municipality();
        $snapshot = $this->seedSnapshot($municipality);

        $member = User::create(['name' => 'Member', 'email' => 'm@example.test', 'password' => 'secret123']);
        $member->municipalities()->attach($municipality->id);

        $response = $this->actingAs($member)->get(route('documents.ageing', ['ageingSnapshot' => $snapshot->id]));
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));

        $outsider = User::create(['name' => 'Outsider', 'email' => 'o@example.test', 'password' => 'secret123']);
        $this->actingAs($outsider)
            ->get(route('documents.ageing', ['ageingSnapshot' => $snapshot->id]))
            ->assertForbidden();
    }
}
