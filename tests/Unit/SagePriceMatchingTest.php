<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Sage\SagePriceImportService;
use PHPUnit\Framework\TestCase;

/**
 * The class → billable matching logic, offline. Class codes/descriptions and
 * billable items mirror the real Binga RDC data shapes.
 */
class SagePriceMatchingTest extends TestCase
{
    private function resolve(array $classes, array $items): array
    {
        $service = new SagePriceImportService;

        return $service->resolveClassPrices(
            array_map(fn ($c) => (object) ['IdCliClass' => $c[0], 'Code' => $c[1], 'Description' => $c[2]], $classes),
            array_map(fn ($i) => $service->makeItem(...$i), $items),
        );
    }

    public function test_lease_classes_match_their_identical_item_code_exactly(): void
    {
        $resolved = $this->resolve(
            [[1, 'LEA-R-H-200m2-P3SP3', 'Lease-Residential High-200m2(P3SP3)']],
            [
                ['LEA-R-H-200m2-P3SP3', 'Lease-Residential High-200m2(P3SP3)', 15.0],
                ['LEA-R-H-300m2-P3SP3', 'Lease-Residential High-300m2(P3SP3)', 15.0],
            ],
        );

        $this->assertSame(['price' => 15.0, 'via' => 'exact', 'item' => 'LEA-R-H-200m2-P3SP3'], $resolved['LEA-R-H-200M2-P3SP3']);
    }

    public function test_assessment_class_matches_its_density_band_taking_the_lower_tenure_rate(): void
    {
        $items = [
            ['P3SP3-ASS RATE004', 'Property Assessnt Rate-Medium  communal (P3SP3)', 2.0],
            ['P3SP3-ASS RATE012', 'Property Assmnt Rate-Medium Density state (P3SP3)', 2.5],
            ['P3SP3-ASS RATE005', 'Property Assessnt Rate-High  communal (P3SP3)', 1.0],
        ];

        $resolved = $this->resolve(
            [[1, 'ASS R-RES-MED-P3SP3', 'Assesment Rates-Reside-Medium-Binga Town-(P3SP3)']],
            $items,
        );

        // Medium band matched; communal ($2.00) preferred over stateland ($2.50).
        $this->assertSame('P3SP3-ASS RATE004', $resolved['ASS R-RES-MED-P3SP3']['item']);
        $this->assertSame(2.0, $resolved['ASS R-RES-MED-P3SP3']['price']);
    }

    public function test_licence_classes_distinguish_town_from_rural_variants(): void
    {
        $items = [
            ['P1SP4- LIC004', 'Business licences-General Dealer-Urban (P1SP4)', 25.0],
            ['P1SP4- LIC005', 'Business licences-General Dealer-Communal (P1SP4)', 19.91],
        ];

        $resolved = $this->resolve(
            [
                [1, 'LIC-G/DEA-TWN-P1SP4', 'Licence-G/Dealer-Binga Town-(P1SP4)'],
                [2, 'LIC-G/D-RUR-P1SP4', 'Licence-General Dealer-Binga Rural-(P1SP4)'],
            ],
            $items,
        );

        $this->assertSame(25.0, $resolved['LIC-G/DEA-TWN-P1SP4']['price']);
        $this->assertSame(19.91, $resolved['LIC-G/D-RUR-P1SP4']['price']);
    }

    public function test_commercial_refuse_matches_the_business_billable_across_taxonomies(): void
    {
        $resolved = $this->resolve(
            [[1, 'REF-COMM-TWN-P2SP1', 'Refuse-Commercial-B/Twn-P2SP1']],
            [['P2SP1-REF FEE002', 'Refuse collection-Businesss areas (P2SP1)', 8.0]],
        );

        $this->assertSame(8.0, $resolved['REF-COMM-TWN-P2SP1']['price']);
    }

    public function test_recurring_sewer_charge_beats_the_pricier_connection_fee_on_ties(): void
    {
        $items = [
            ['P2SP1-SEW004', 'Sewer connection-Business (P2SP1)', 150.0],
            ['P2SP1-SEW001', 'Sewer charges-Business (P2SP1)', 36.0],
        ];

        $resolved = $this->resolve(
            [[1, 'SEWER-COMM-TWN-P2SP1', 'Sewer-Commercial-Binga Town-(P2SP1)']],
            $items,
        );

        $this->assertSame(36.0, $resolved['SEWER-COMM-TWN-P2SP1']['price']);
    }

    public function test_zero_priced_billables_and_unrelated_classes_do_not_match(): void
    {
        $resolved = $this->resolve(
            [
                [1, 'REF-RES-HIGH-P2SP1', 'Refuse-Res-High-B/Twn-P2SP1'],
                [2, 'VACANT STANDS', 'VACANT STANDS'],
                [3, 'SCH ADM FEE-P3SP1', 'School Admin Fee-P3SP1'],
            ],
            [
                ['P2SP1-REF FEE001', 'Refuse fee-Residential areas - High (P2SP1)', 0.0],
                ['P3SP3-ASS RATE005', 'Property Assessnt Rate-High  communal (P3SP3)', 1.0],
            ],
        );

        $this->assertSame([], $resolved);
    }
}
