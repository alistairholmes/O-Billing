@extends('pdf.layout')

@section('title', "Invoice {$invoice->invoice_number}")

@section('content')
    @include('pdf.partials.invoice', ['invoice' => $invoice, 'municipality' => $municipality])
@endsection
