{{-- Every invoice of a billing run, one per page, for bulk printing/mailing. --}}
@extends('pdf.layout')

@section('title', "Invoices — {$run->run_number}")

@section('content')
    @forelse ($invoices as $invoice)
        @include('pdf.partials.invoice', ['invoice' => $invoice, 'municipality' => $municipality])

        @unless ($loop->last)
            <div class="page-break"></div>
        @endunless
    @empty
        <p class="muted">This billing run has no invoices.</p>
    @endforelse
@endsection
