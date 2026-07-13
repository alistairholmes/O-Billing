<?php

declare(strict_types=1);

namespace App\Models\Sage;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sage `_mtblBillingRuns` — a run that raised charges for a billing period.
 * `cBillingRunNumber` is the run reference, `iBillingRunPeriodID` the period
 * billed, and `bBillingRunProcessed` whether it has been finalised (posted to
 * the ledger).
 */
class BillingRun extends SageModel
{
    protected $table = '_mtblBillingRuns';

    protected $primaryKey = 'idBillingRun';

    protected function casts(): array
    {
        return [
            'bBillingRunProcessed' => 'boolean',
            'iSysDateProcessed' => 'datetime',
            'iSysDateCalculated' => 'datetime',
            'dBillingCycleDate' => 'datetime',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'iBillingRunPeriodID', 'idPeriod');
    }
}
