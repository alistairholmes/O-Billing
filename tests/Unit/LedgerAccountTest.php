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

    /**
     * Codes are typed by hand, and these three defects each still name their
     * service unambiguously — discarding them would drop a real charge, and
     * reading them literally invented service types like "LEDGER-A026".
     */
    public function test_it_recovers_the_service_from_hand_typing_defects(): void
    {
        // Trailing separator shifts every segment along.
        $this->assertSame(['DWA026', 'DEV'], LedgerAccount::split('DWA026-DEV-A026-'));
        // "=" typed instead of "-" before the portion.
        $this->assertSame(['ESTV006', 'DEV'], LedgerAccount::split('ESTV006-DEV=P3SP3'));
        // "/" typed instead of "-", where the service legitimately contains "/".
        $this->assertSame(['MNH170', 'S/EXT'], LedgerAccount::split('MNH170-S/EXT/P6SP1'));
    }

    /**
     * A number in the service position is a stand sub-number, not a service.
     * Inventing "013" as a billable is worse than admitting we cannot tell.
     */
    public function test_a_numeric_segment_names_no_service(): void
    {
        $this->assertSame(['BAN', '(other)'], LedgerAccount::split('BAN-013-P3SP3'));
        $this->assertSame(['NYP', '(other)'], LedgerAccount::split('NYP-807-P2SP1'));
        $this->assertSame(['CHNUS044', '(other)'], LedgerAccount::split('CHNUS044-1-P3SP3'));
    }

    /** Real tokens must survive all of the above, including the short ones. */
    public function test_real_service_tokens_are_untouched(): void
    {
        $this->assertSame(['MGW003', 'DEVLEVY'], LedgerAccount::split('MGW003-DEVLEVY-P3SP3'));
        $this->assertSame(['MNH170', 'S/EXT'], LedgerAccount::split('MNH170-S/EXT-P6SP1'));
        $this->assertSame(['BGATWN-40', 'AS'], LedgerAccount::split('BGATWN-40-AS-P1SP4'));
        $this->assertSame(['PLT006', 'ASSR'], LedgerAccount::split('PLT006-ASSR-P1SP4'));
    }
}
