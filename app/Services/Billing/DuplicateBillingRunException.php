<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\BillingRun;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * Thrown when generating a billing run would bill customers twice: another
 * completed run already covers an overlapping scope for the same billing
 * period, or was generated earlier the same day.
 */
final class DuplicateBillingRunException extends RuntimeException
{
    /** @param Collection<int, BillingRun> $conflicts */
    public function __construct(BillingRun $run, public readonly Collection $conflicts)
    {
        $numbers = $conflicts->pluck('run_number')->implode(', ');

        parent::__construct(
            "Generating {$run->run_number} would charge customers twice: completed run(s) {$numbers} "
            .'already bill an overlapping scope for the same period or were generated today. '
            ."If the other run was a mistake, reverse it first; otherwise narrow this run's scope "
            .'(services, account range or location range).'
        );
    }
}
