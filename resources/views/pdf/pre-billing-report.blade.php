{{-- Pre-billing report: the dry-run projection of a draft run. Expects $run, $preview, $municipality. --}}
@extends('pdf.layout')

@section('title', "Pre-billing report — {$run->run_number}")

@section('content')
    @php use App\Support\Currencies; @endphp

    @include('pdf.partials.letterhead', [
        'municipality' => $municipality,
        'title' => 'Pre-billing Report',
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
            <td style="width: 30%;">
                <div class="label">Scope</div>
                <div class="value">{{ $run->scopeSummary() }}</div>
            </td>
            <td style="width: 20%;">
                <div class="label">Projected invoices</div>
                <div class="value">{{ number_format($preview['invoice_count']) }}</div>
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>Account</th>
                <th>Property</th>
                <th>Currency</th>
                <th class="num">Subtotal</th>
                <th class="num">{{ $municipality->tax_label ?: 'Tax' }}</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($preview['invoices'] as $inv)
                <tr>
                    <td class="mono">{{ $inv['account_number'] }}</td>
                    <td>{{ $inv['customer_name'] }}</td>
                    <td>{{ $inv['currency'] }}</td>
                    <td class="num">{{ Currencies::format($inv['subtotal'], $inv['currency']) }}</td>
                    <td class="num">{{ Currencies::format($inv['tax_total'], $inv['currency']) }}</td>
                    <td class="num">{{ Currencies::format($inv['total'], $inv['currency']) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Nothing would be billed for this run's scope.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        @forelse ($preview['currency_totals'] as $currency => $amount)
            <tr class="{{ $loop->first ? 'grand' : '' }}">
                <td class="{{ $loop->first ? '' : 'muted' }}">Projected total ({{ $currency }})</td>
                <td class="num">{{ Currencies::format($amount, $currency) }}</td>
            </tr>
        @empty
            <tr><td class="muted">Projected total</td><td class="num">—</td></tr>
        @endforelse
    </table>
@endsection
