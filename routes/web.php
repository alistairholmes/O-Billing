<?php

use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Printable / downloadable PDF documents. Inline by default (print from the
// browser's PDF viewer); pass ?download=1 for an attachment.
Route::middleware('auth')->prefix('documents')->name('documents.')->group(function () {
    Route::get('invoices/{invoice}/pdf', [DocumentController::class, 'invoice'])
        ->name('invoice');
    Route::get('billing-runs/{billingRun}/invoices/pdf', [DocumentController::class, 'billingRunInvoices'])
        ->name('billing-run.invoices');
    Route::get('billing-runs/{billingRun}/pre-billing/pdf', [DocumentController::class, 'preBillingReport'])
        ->name('billing-run.pre-billing');
    Route::get('billing-runs/{billingRun}/post-billing/pdf', [DocumentController::class, 'postBillingReport'])
        ->name('billing-run.post-billing');
});
