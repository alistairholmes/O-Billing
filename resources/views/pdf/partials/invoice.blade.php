{{-- One invoice's body (letterhead, parties, lines, totals). Expects $invoice, $municipality. --}}
@php
    use App\Support\Currencies;

    $customer = $invoice->customer;
@endphp

@include('pdf.partials.letterhead', [
    'municipality' => $municipality,
    'title' => 'Tax Invoice',
    'subtitle' => $invoice->invoice_number,
])

<table class="meta-grid">
    <tr>
        <td style="width: 50%;">
            <div class="label">Billed to</div>
            <div class="value">{{ $customer->name }}</div>
            <div>Account <span class="mono">{{ $customer->account_number }}</span></div>
            @if ($customer->address)
                <div>{{ $customer->address }}</div>
            @endif
            @if ($customer->area)
                <div>{{ $customer->area->name }}</div>
            @endif
        </td>
        <td style="width: 25%;">
            <div class="label">Invoice number</div>
            <div class="value mono">{{ $invoice->invoice_number }}</div>
            <div class="label">Billing period</div>
            <div class="value">{{ $invoice->period_month?->format('F Y') }}</div>
        </td>
        <td style="width: 25%;">
            <div class="label">Issued</div>
            <div class="value">{{ $invoice->issued_at?->format('d M Y') ?? '—' }}</div>
            <div class="label">Status</div>
            <div class="value" style="text-transform: capitalize;">{{ $invoice->status }}</div>
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th>Description</th>
            <th class="num">Qty</th>
            <th class="num">Unit</th>
            <th class="num">Amount</th>
            <th class="num">{{ $municipality->tax_label ?: 'Tax' }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($invoice->lines as $line)
            <tr>
                <td>{{ $line->description }}</td>
                <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 4), '0'), '.') }}</td>
                <td class="num">{{ Currencies::format($line->unit_amount, $invoice->currency) }}</td>
                <td class="num">{{ Currencies::format($line->amount, $invoice->currency) }}</td>
                <td class="num">{{ Currencies::format($line->tax_amount, $invoice->currency) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr>
        <td class="muted">Subtotal</td>
        <td class="num">{{ Currencies::format($invoice->subtotal, $invoice->currency) }}</td>
    </tr>
    <tr>
        <td class="muted">{{ $municipality->tax_label ?: 'Tax' }}</td>
        <td class="num">{{ Currencies::format($invoice->tax_total, $invoice->currency) }}</td>
    </tr>
    <tr class="grand">
        <td>Total due</td>
        <td class="num">{{ Currencies::format($invoice->total, $invoice->currency) }}</td>
    </tr>
</table>
