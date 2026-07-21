<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Service;

/**
 * Shared option lists for the "scope" fields (services, accounts, locations)
 * used by both the billing-run form and the billing-schedule form.
 */
final class BillingScopeOptions
{
    /** @return array<int, string> service id => "Group (Variant)" */
    public static function services(): array
    {
        return Service::query()
            ->where('active', true)
            ->with('serviceType')
            ->get()
            ->mapWithKeys(fn (Service $s) => [$s->id => $s->displayName()])
            ->all();
    }

    /** @return array<string, string> account_number => "ACC-1001 — Name" */
    public static function accounts(): array
    {
        return Customer::query()
            ->orderBy('account_number')
            ->get(['account_number', 'name'])
            ->mapWithKeys(fn (Customer $c) => [$c->account_number => "{$c->account_number} — {$c->name}"])
            ->all();
    }

    /** @return array<int, string> area id => suburb path label */
    public static function locations(): array
    {
        return Area::billingLevel()
            ->with('parent')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Area $a) => [$a->id => $a->pathLabel()])
            ->all();
    }
}
