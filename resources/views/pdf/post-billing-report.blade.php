{{-- Post-billing report: the invoice register of a completed run. Expects $run, $invoices, $municipality. --}}
@extends('pdf.layout')

@section('title', "Post-billing report — {$run->run_number}")

@section('content')
    @php use App\Support\Currencies; @endphp

    @include('pdf.partials.letterhead', [
        'municipality' => $municipality,
        'title' => 'Post-billing Report',
        'subtitle' => $run->run_number,
    ])

    <table class="meta-grid">
        <tr>
            <td style="width: 25%;">
                <div class="label">Run number</div>
                <div class="value mono">{{ $run->run_number }}</div>
            </td>
            <td style="width: 25%;">
                <div class="label">Period &middot; Frequency</div>
                <div class="value">{{ $run->period_month?->format('F Y') }} &middot; {{ \App\Models\BillingRun::frequencies()[$run->frequency] ?? $run->frequency }}</div>
            </td>
            <td style="width: 25%;">
                <div class="label">Processed</div>
                <div class="value">{{ $run->run_at?->format('d M Y H:i') ?? '—' }}</div>
            </td>
            <td style="width: 25%;">
                <div class="label">Invoices raised</div>
                <div class="value">{{ number_format($invoices->count()) }}</div>
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Invoice #</th>
                <th>Account</th>
                <th>Property</th>
                <th>Status</th>
                <th class="num">Subtotal</th>
                <th class="num">{{ $municipality->tax_label ?: 'Tax' }}</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($invoices as $invoice)
                <tr>
                    <td class="mono">{{ $invoice->invoice_number }}</td>
                    <td class="mono">{{ $invoice->customer?->account_number }}</td>
                    <td>{{ $invoice->customer?->name }}</td>
                    <td style="text-transform: capitalize;">{{ $invoice->status }}</td>
                    <td class="num">{{ Currencies::format($invoice->subtotal, $invoice->currency) }}</td>
                    <td class="num">{{ Currencies::format($invoice->tax_total, $invoice->currency) }}</td>
                    <td class="num">{{ Currencies::format($invoice->total, $invoice->currency) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">This run raised no invoices.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        @forelse ($run->currency_totals ?? [] as $currency => $amount)
            <tr class="{{ $loop->first ? 'grand' : '' }}">
                <td class="{{ $loop->first ? '' : 'muted' }}">Total billed ({{ $currency }})</td>
                <td class="num">{{ Currencies::format($amount, $currency) }}</td>
            </tr>
        @empty
            <tr><td class="muted">Total billed</td><td class="num">—</td></tr>
        @endforelse
    </table>
@endsection
