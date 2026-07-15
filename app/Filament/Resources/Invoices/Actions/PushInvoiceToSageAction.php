<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Actions;

use App\Models\Invoice;
use App\Services\Sage\SageAdHocBillingWriter;
use App\Services\Sage\SageBillingRunPoster;
use App\Support\Currencies;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Sends a single invoice to Sage, in whichever shape the write target expects:
 *
 *  • Property-module companies: staged as PENDING ad-hoc billing via
 *    {@see SageAdHocBillingWriter} — a Sage operator Calculates & Processes the
 *    batch, so nothing posts automatically.
 *
 *  • Debtors-ledger companies (e.g. Gokwe South): posted straight in as POSTED
 *    invoice documents via {@see SageBillingRunPoster::postInvoice()} — one Sage
 *    document per charge line, with the debtor transaction and balanced GL
 *    double entry, exactly like posting a whole billing run. The confirmation
 *    modal shows a live dry-run preview and a double-post guard.
 */
final class PushInvoiceToSageAction
{
    public static function make(): Action
    {
        return Action::make('pushToSage')
            ->label('Push to Sage')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Push invoice to Sage')
            ->modalDescription(fn (Invoice $record) => self::staging()
                ? self::stagingDescription()
                : self::postingPreview($record))
            ->modalSubmitActionLabel(fn () => self::staging() ? 'Stage in Sage' : 'Post invoice to Sage')
            ->action(function (Invoice $record): void {
                if (self::staging()) {
                    self::stage($record);
                } else {
                    self::post($record);
                }
            });
    }

    private static function staging(): bool
    {
        return app(SageAdHocBillingWriter::class)->targetsAdHocBilling();
    }

    // ------------------------------------------------------------------
    // Property-module target: stage as pending ad-hoc billing
    // ------------------------------------------------------------------

    private static function stagingDescription(): string
    {
        return 'Stages this invoice as pending ad-hoc billing in "'
            .config('database.connections.sage_write.database')
            .'". A Sage operator then Calculates & Processes it to post the charge — nothing posts automatically.';
    }

    private static function stage(Invoice $record): void
    {
        $result = app(SageAdHocBillingWriter::class)
            ->pushInvoice($record->load(['lines.service', 'customer']));

        if (! ($result['ok'] ?? false)) {
            Notification::make()->danger()->title('Push failed')
                ->body($result['error'] ?? 'Unknown error')->persistent()->send();

            return;
        }

        $entries = collect($result['batches'])->sum('entries');
        Notification::make()->success()->title('Staged in Sage')
            ->body("{$entries} charge line(s) staged in \"{$result['database']}\" as ad-hoc billing. Calculate & Process the batch in Sage to post it.")
            ->persistent()->send();

        if (! empty($result['unresolved'])) {
            Notification::make()->warning()->title('Some lines skipped')
                ->body('No matching Sage service for: '.implode('; ', $result['unresolved']))
                ->persistent()->send();
        }
    }

    // ------------------------------------------------------------------
    // Debtors-ledger target: post as posted invoice documents
    // ------------------------------------------------------------------

    /** The live dry-run summary shown in the confirmation modal. */
    private static function postingPreview(Invoice $record): HtmlString
    {
        try {
            $p = app(SageBillingRunPoster::class)->previewInvoice($record);
        } catch (Throwable $e) {
            return new HtmlString('<strong>Could not reach Sage:</strong> '.e($e->getMessage()));
        }

        $usd = collect($p['by_token'])->sum('usd_incl');

        $parts = [];
        $parts[] = '<strong>'.count($p['docs']).'</strong> Sage invoice document(s) · <strong>'
            .Currencies::format($usd, 'USD').'</strong> (rate '.$p['exchange_rate'].' ZWG/USD) → '
            .e($p['database']).', numbered from <strong>'.e($p['next_invoice_number']).'</strong>.';

        if ($p['unresolved'] !== []) {
            $parts[] = '<strong>'.count($p['unresolved']).' charge(s) could not be matched to a Sage account/item</strong> and will be skipped: '
                .e(implode('; ', $p['unresolved']));
        }
        if ($p['already_posted'] > 0) {
            $parts[] = '<strong>This invoice is already posted in Sage</strong> — posting again is blocked because it would double-bill.';
        }

        $parts[] = 'Posting writes the invoice document(s) and the balanced double entry directly: '
            .'the ratepayer account is <strong>debited</strong>, the service revenue account and VAT control are '
            .'<strong>credited</strong>, and statements, the trial balance and account enquiries update immediately.';

        return new HtmlString(implode('<br><br>', $parts));
    }

    private static function post(Invoice $record): void
    {
        try {
            $result = app(SageBillingRunPoster::class)->postInvoice($record);
        } catch (Throwable $e) {
            Notification::make()->danger()->title('Posting to Sage failed')
                ->body($e->getMessage())->send();

            return;
        }

        if (isset($result['error'])) {
            Notification::make()->warning()->title('Not posted')
                ->body($result['error'])->send();

            return;
        }

        $usd = collect($result['by_token'])->sum('usd_incl');
        $documents = $result['invoice_from'] === $result['invoice_to']
            ? "Document {$result['invoice_from']}"
            : "Documents {$result['invoice_from']} … {$result['invoice_to']}";

        Notification::make()->success()->persistent()
            ->title("Posted invoice {$record->invoice_number} to Sage")
            ->body("{$documents}, ".Currencies::format($usd, 'USD')
                .'. Debtor debited, revenue and VAT credited — the account enquiry in Sage Evolution reflects it immediately.')
            ->send();

        if (! empty($result['unresolved'])) {
            Notification::make()->warning()->title('Some lines skipped')
                ->body(implode('; ', $result['unresolved']))->persistent()->send();
        }
    }
}
