<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Sage\LedgerAccount;
use PHPUnit\Framework\TestCase;

class LedgerAccountTest extends TestCase
{
    public function test_it_takes_the_service_token_from_the_second_to_last_segment(): void
    {
        $this->assertSame(['CUN243', 'LEA'], LedgerAccount::split('CUN243-LEA-(P3SP3)'));
        $this->assertSame(['BGATWN-40', 'ASS'], LedgerAccount::split('BGATWN-40-ASS-P3SP3'));
    }

    /**
     * A two-segment code is usually {stand}-{portion} with no service at all.
     * Reading the portion as a service invented "LEDGER-P3SP3" service types
     * for ~2,000 Zaka ratepayers and mis-grouped them.
     */
    public function test_a_portion_segment_is_not_mistaken_for_a_service(): void
    {
        $this->assertSame(['CUN243', '(other)'], LedgerAccount::split('CUN243-P3SP3'));
        $this->assertSame(['CUN243', '(other)'], LedgerAccount::split('CUN243-(P6SP1)'));
        $this->assertSame(['STAND1', '(other)'], LedgerAccount::split('STAND1-P2SP1-(P2SP1)'));
    }

    public function test_a_bare_stand_has_no_token(): void
    {
        $this->assertSame(['CUN243', '(other)'], LedgerAccount::split('CUN243'));
    }
}
