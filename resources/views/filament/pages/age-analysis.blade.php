@php
    $snapshot = $this->getSnapshot();
    $rowsByCurrency = $this->getRowsByCurrency();
    $buckets = $this->buckets();
    $snapshots = $this->getSnapshots();
@endphp

<x-filament-panels::page>
    @if ($snapshot === null)
        <x-filament::section>
            <x-slot name="heading">No age analysis captured yet</x-slot>
            <x-slot name="description">
                Debtor balances live in Sage, not in O-Billing — payments are receipted there.
                Use <strong>Refresh from Sage</strong> above to capture a snapshot; it runs on the
                Sage worker and reads only, writing nothing to the council's books.
            </x-slot>
        </x-filament::section>
    @else
        @if ($snapshots->count() > 1)
            <x-filament::section compact>
                <x-slot name="heading">Snapshot</x-slot>
                <div class="flex flex-wrap gap-2">
                    @foreach ($snapshots as $option)
                        <x-filament::button
                            :color="$option->id === $snapshot->id ? 'primary' : 'gray'"
                            size="xs"
                            tag="button"
                            wire:click="$set('snapshotId', {{ $option->id }})"
                        >
                            {{ $option->as_at->format('j M Y') }}
                        </x-filament::button>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        @foreach ($rowsByCurrency as $currency => $rows)
            @php
                $totals = [];
                foreach (array_keys($buckets) as $column) {
                    $totals[$column] = $rows->sum(fn ($r) => (float) $r->{$column});
                }
                $grandTotal = $rows->sum(fn ($r) => (float) $r->total);
                $overdue = $totals['days_90'] + $totals['days_120_plus'];
            @endphp

            <x-filament::section>
                <x-slot name="heading">{{ $currency }}</x-slot>
                <x-slot name="description">
                    {{ number_format($rows->sum('account_count')) }} accounts ·
                    {{ number_format($grandTotal, 2) }} outstanding ·
                    {{ $grandTotal == 0.0 ? '0' : number_format(100 * $overdue / $grandTotal, 1) }}% over 90 days
                </x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm tabular-nums">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <th class="py-2 pe-3 text-start font-medium text-gray-500 dark:text-gray-400">Service</th>
                                <th class="py-2 px-3 text-end font-medium text-gray-500 dark:text-gray-400">Accounts</th>
                                @foreach ($buckets as $label)
                                    <th class="py-2 px-3 text-end font-medium text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ $label }}</th>
                                @endforeach
                                <th class="py-2 ps-3 text-end font-medium text-gray-950 dark:text-white">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-2 pe-3">
                                        <span class="font-medium text-gray-950 dark:text-white">{{ $row->service_label }}</span>
                                        @if ($row->service_label !== $row->service_token)
                                            <span class="ms-1 text-xs text-gray-400">{{ $row->service_token }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 px-3 text-end text-gray-500 dark:text-gray-400">{{ number_format($row->account_count) }}</td>
                                    @foreach (array_keys($buckets) as $column)
                                        <td @class([
                                            'py-2 px-3 text-end',
                                            'text-danger-600 dark:text-danger-400' => $column === 'days_120_plus' && (float) $row->{$column} != 0.0,
                                            'text-gray-600 dark:text-gray-300' => $column !== 'days_120_plus',
                                        ])>
                                            {{ (float) $row->{$column} == 0.0 ? '—' : number_format((float) $row->{$column}, 2) }}
                                        </td>
                                    @endforeach
                                    <td class="py-2 ps-3 text-end font-medium text-gray-950 dark:text-white">{{ number_format((float) $row->total, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-300 dark:border-white/20 font-semibold">
                                <td class="py-2 pe-3 text-gray-950 dark:text-white">Total</td>
                                <td class="py-2 px-3 text-end text-gray-500 dark:text-gray-400">{{ number_format($rows->sum('account_count')) }}</td>
                                @foreach (array_keys($buckets) as $column)
                                    <td class="py-2 px-3 text-end text-gray-950 dark:text-white">{{ number_format($totals[$column], 2) }}</td>
                                @endforeach
                                <td class="py-2 ps-3 text-end text-gray-950 dark:text-white">{{ number_format($grandTotal, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </x-filament::section>
        @endforeach

        <p class="text-xs text-gray-500 dark:text-gray-400">
            Aged from {{ number_format($snapshot->transaction_count) }} open Sage transactions across
            {{ number_format($snapshot->account_count) }} debtor accounts. Balances are aged against
            {{ $snapshot->as_at->format('j F Y') }} — the most recent transaction in the ledger, not today,
            so a council that has not posted for some time is not shown as wholly overdue by default.
        </p>
    @endif
</x-filament-panels::page>
