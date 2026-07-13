<?php

declare(strict_types=1);

namespace App\Models\Sage;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sage `Client` — the standard Evolution debtor (account holder / ratepayer):
 * `Account`, `Name`, physical/postal address, telephone, e-mail, balance
 * (`DCBalance`) and currency (`iCurrencyID`). Keyed by `DCLink`.
 */
class Client extends SageModel
{
    protected $table = 'Client';

    protected $primaryKey = 'DCLink';

    protected function casts(): array
    {
        return [
            'DCBalance' => 'float',
        ];
    }

    public function portions(): HasMany
    {
        return $this->hasMany(PropertyPortion::class, 'iPortionConsumerID', 'DCLink');
    }

    /** The concatenated physical address lines, blanks removed. */
    public function physicalAddress(): string
    {
        return collect([
            $this->Physical1, $this->Physical2, $this->Physical3,
            $this->Physical4, $this->Physical5,
        ])->filter()->implode(', ');
    }
}
