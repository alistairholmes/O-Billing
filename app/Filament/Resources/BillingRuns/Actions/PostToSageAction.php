<?php

declare(strict_types=1);

namespace App\Filament\Resources\BillingRuns\Actions;

use App\Jobs\Sage\PostBillingRun;
use App\Models\BillingRun;
use App\Support\Sage\SageBridge;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * "Post to Sage" — queues a completed billing run to be posted into Sage
 * Evolution by the Sage worker (via {@see PostBillingRun}). The cloud app has no
 * Sage connection, so it records a SageOperation and dispatches the job; the
 * worker posts the invoice documents + debtor and GL entries and notifies the
 * user.
 *
 * Posting a large run streams thousands of documents over the tunnel and can be
 * interrupted (a dropped connection). Because the worker posts each invoice in
 * its own transaction and skips any already in Sage, an interrupted post is
 * simply RESUMED: when the last attempt failed, this action becomes "Resume
 * posting" and re-queues with force, picking up exactly where it stopped — no
 * duplicates. The run's posting state is shown on the runs table so a partial
 * post is never mistaken for a finished one.
 */
final class PostToSageAction
{
    public static function make(): Action
    {
        return Action::make('postToSage')
            ->label(fn (BillingRun $record) => match ($record->posting_status) {
                'posting' => 'Posting…',
                'failed' => 'Resume posting to Sage',
                default => 'Post to Sage',
            })
            ->icon(fn (BillingRun $record) => $record->posting_status === 'failed'
                ? Heroicon::OutlinedArrowPath
                : Heroicon::OutlinedCloudArrowUp)
            ->color(fn (BillingRun $record) => $record->posting_status === 'failed' ? 'warning' : 'info')
            ->visible(fn (BillingRun $record) => $record->isCompleted() && $record->posting_status !== 'posted')
            ->disabled(fn (BillingRun $record) => $record->posting_status === 'posting')
            ->requiresConfirmation()
            ->modalHeading(fn (BillingRun $record) => $record->posting_status === 'failed'
                ? 'Resume posting to Sage'
                : 'Post billing run to Sage')
            ->modalDescription(fn (BillingRun $record) => new HtmlString(
                $record->posting_status === 'failed'
                    ? 'The last posting attempt did not finish (e.g. the connection dropped). '
                        .'This <strong>resumes</strong> it: invoices already posted to Sage are skipped and '
                        .'the rest are posted, so <strong>nothing is duplicated</strong>. '
                        .'You will be notified when the worker finishes.'
                    : 'This queues the run to be posted to Sage by the Sage worker: each ratepayer account is '
                        .'<strong>debited</strong> and the service revenue accounts and VAT control are <strong>credited</strong>. '
                        .'The double-post guard prevents duplicates. You will be notified when the worker finishes.'
            ))
            ->modalSubmitActionLabel(fn (BillingRun $record) => $record->posting_status === 'failed'
                ? 'Resume posting'
                : 'Queue posting')
            ->action(function (BillingRun $record): void {
                // A failed run may be partly posted — resume with force so the
                // worker skips what's already in Sage and finishes the rest.
                $resume = $record->posting_status === 'failed';

                $record->forceFill(['posting_status' => 'posting'])->save();

                SageBridge::queue('post_run', PostBillingRun::class, $record, ['mode' => 'post', 'force' => $resume]);

                Notification::make()
                    ->success()
                    ->title($resume ? 'Resuming posting' : 'Posting queued')
                    ->body($resume
                        ? 'The run is being resumed; already-posted invoices are skipped. Watch progress under Sage → Sage Operations.'
                        : 'The run has been queued to post to Sage. You will be notified when it completes; watch progress under Sage → Sage Operations.')
                    ->send();
            });
    }
}
