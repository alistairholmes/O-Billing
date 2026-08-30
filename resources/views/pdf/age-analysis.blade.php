{{-- Debtor age analysis per service. Expects $snapshot, $rowsByCurrency, $buckets, $municipality. --}}
@extends('pdf.layout')

@section('title', "Age analysis — {$snapshot->as_at->format('j M Y')}")

@section('content')
    @include('pdf.partials.letterhead', [
        'municipality' => $municipality,
        'title' => 'Debtor Age Analysis',
        'subtitle' => 'As at '.$snapshot->as_at->format('j F Y'),
    ])

    <table class="meta-grid">
        <tr>
            <td style="width: 25%;">
                <div class="label">Balances aged to</div>
                <div class="value">{{ $snapshot->as_at->format('d M Y') }}</div>
            </td>
            <td style="width: 25%;">
                <div class="label">Debtor accounts</div>
                <div class="value">{{ number_format($snapshot->account_count) }}</div>
            </td>
            <td style="width: 25%;">
                <div class="label">Open transactions</div>
                <div class="value">{{ number_format($snapshot->transaction_count) }}</div>
            </td>
            <td style="width: 25%;">
                <div class="label">Captured</div>
                <div class="value">{{ $snapshot->created_at?->format('d M Y H:i') ?? '—' }}</div>
            </td>
        </tr>
    </table>

    @foreach ($rowsByCurrency as $currency => $rows)
        @php
            $totals = [];
            foreach (array_keys($buckets) as $column) {
                $totals[$column] = $rows->sum(fn ($r) => (float) $r->{$column});
            }
            $grandTotal = $rows->sum(fn ($r) => (float) $r->total);
            $overdue = $totals['days_90'] + $totals['days_120_plus'];
        @endphp

        <h2 style="margin: 18px 0 6px; font-size: 11pt;">
            {{ $currency }}
            <span style="font-weight: normal; font-size: 8.5pt; color: #666;">
                &middot; {{ number_format($rows->sum('account_count')) }} accounts
                &middot; {{ $grandTotal == 0.0 ? '0' : number_format(100 * $overdue / $grandTotal, 1) }}% over 90 days
            </span>
        </h2>

        <table class="lines">
            <thead>
                <tr>
                    <th>Service</th>
                    <th class="num">Accounts</th>
                    @foreach ($buckets as $label)
                        <th class="num">{{ $label }}</th>
                    @endforeach
                    <th class="num">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>
                            {{ $row->service_label }}
                            @if ($row->service_label !== $row->service_token)
                                <span style="color: #888; font-size: 7.5pt;">{{ $row->service_token }}</span>
                            @endif
                        </td>
                        <td class="num">{{ number_format($row->account_count) }}</td>
                        @foreach (array_keys($buckets) as $column)
                            <td class="num">{{ (float) $row->{$column} == 0.0 ? '—' : number_format((float) $row->{$column}, 2) }}</td>
                        @endforeach
                        <td class="num"><strong>{{ number_format((float) $row->total, 2) }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td><strong>Total {{ $currency }}</strong></td>
                    <td class="num"><strong>{{ number_format($rows->sum('account_count')) }}</strong></td>
                    @foreach (array_keys($buckets) as $column)
                        <td class="num"><strong>{{ number_format($totals[$column], 2) }}</strong></td>
                    @endforeach
                    <td class="num"><strong>{{ number_format($grandTotal, 2) }}</strong></td>
                </tr>
            </tfoot>
        </table>
    @endforeach

    @if ($rowsByCurrency->isEmpty())
        <p>No outstanding balances were found in this snapshot.</p>
    @endif

    <p style="margin-top: 16px; font-size: 8pt; color: #666;">
        Outstanding balances are taken from Sage, per transaction, after receipts and credit notes.
        Each debtor account carries one service, so the ageing is grouped by the service in the
        account code. Balances are aged against {{ $snapshot->as_at->format('j F Y') }} — the latest
        transaction in the ledger rather than today's date, so a gap in posting does not by itself
        push every balance into the oldest bucket. Source company: {{ $snapshot->source_database }}.
    </p>
@endsection
