@php
    use App\Support\Currencies;
@endphp

<div class="space-y-4 text-sm">
    <div class="grid grid-cols-2 gap-3">
        <div>
            <div class="text-gray-500">Run number</div>
            <div class="font-medium">{{ $run->run_number }}</div>
        </div>
        <div>
            <div class="text-gray-500">Period · Frequency</div>
            <div class="font-medium">{{ $run->period_month?->format('F Y') }} · {{ \App\Models\BillingRun::frequencies()[$run->frequency] ?? $run->frequency }}</div>
        </div>
        <div>
            <div class="text-gray-500">Scope</div>
            <div class="font-medium">{{ $run->scopeSummary() }}</div>
        </div>
        <div>
            <div class="text-gray-500">Projected invoices</div>
            <div class="font-medium">{{ number_format($preview['invoice_count']) }}</div>
        </div>
    </div>

    <div>
        <div class="text-gray-500 mb-1">Projected totals</div>
        <div class="font-semibold">
            @forelse ($preview['currency_totals'] as $currency => $amount)
                <span class="mr-3">{{ Currencies::format($amount, $currency) }}</span>
            @empty
                —
            @endforelse
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead>
                <tr class="border-b text-gray-500">
                    <th class="py-1 pr-3">Account</th>
                    <th class="py-1 pr-3">Property</th>
                    <th class="py-1 pr-3 text-right">Subtotal</th>
                    <th class="py-1 pr-3 text-right">Tax</th>
                    <th class="py-1 text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($preview['invoices'] as $inv)
                    <tr class="border-b border-gray-100">
                        <td class="py-1 pr-3 font-mono">{{ $inv['account_number'] }}</td>
                        <td class="py-1 pr-3">{{ $inv['customer_name'] }}</td>
                        <td class="py-1 pr-3 text-right">{{ Currencies::format($inv['subtotal'], $inv['currency']) }}</td>
                        <td class="py-1 pr-3 text-right">{{ Currencies::format($inv['tax_total'], $inv['currency']) }}</td>
                        <td class="py-1 text-right font-medium">{{ Currencies::format($inv['total'], $inv['currency']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-2 text-gray-500">Nothing would be billed for this run's scope.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
