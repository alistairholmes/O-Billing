<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\BillingRun;
use App\Services\Billing\BillingRunService;
use App\Support\Currencies;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * Pre-billing review: lists draft (unprocessed) billing runs with a dry-run of
 * what they would bill, so a run can be checked before it is processed. This is
 * the compulsory review step that precedes generating invoices.
 */
class PreBillingReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Pre-billing';

    protected static ?string $title = 'Pre-billing report';

    protected string $view = 'filament.pages.report-table';

    /** @var array<int, array<string, mixed>> Memoised dry-run results per run. */
    protected array $previewCache = [];

    public function table(Table $table): Table
    {
        return $table
            ->query(BillingRun::query()->where('status', 'draft')->latest('period_month'))
            ->emptyStateHeading('No draft runs to review')
            ->emptyStateDescription('Create a billing run, then review it here before processing.')
            ->columns([
                TextColumn::make('run_number')->label('Run #')->searchable()->sortable(),
                TextColumn::make('period_month')->label('Period')->date('F Y')->sortable(),
                TextColumn::make('frequency')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => BillingRun::frequencies()[$state] ?? $state),
                TextColumn::make('scope')->label('Scope')->state(fn (BillingRun $r) => $r->scopeSummary()),
                TextColumn::make('projected_invoices')
                    ->label('Projected invoices')
                    ->badge()
                    ->state(fn (BillingRun $r) => $this->preview($r)['invoice_count']),
                TextColumn::make('projected_total')
                    ->label('Projected total')
                    ->state(fn (BillingRun $r) => $this->formatTotals($this->preview($r)['currency_totals'])),
            ])
            ->recordActions([
                Action::make('detail')
                    ->label('View detail')
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->modalHeading(fn (BillingRun $r) => "Pre-billing — {$r->run_number}")
                    ->modalContent(fn (BillingRun $r) => view('filament.billing.pre-billing-detail', [
                        'run' => $r,
                        'preview' => $this->preview($r),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('process')
                    ->label('Process')
                    ->icon(Heroicon::OutlinedPlayCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Process billing run')
                    ->modalDescription('This generates the invoices and moves the run to post-billing. Re-running later replaces this run\'s invoices.')
                    ->action(function (BillingRun $record): void {
                        $result = app(BillingRunService::class)->generate($record);

                        Notification::make()
                            ->success()
                            ->title('Billing run processed')
                            ->body("{$result['invoice_count']} invoices generated. Total billed: ".($this->formatTotals($result['currency_totals']) ?: '—'))
                            ->send();
                    }),
            ]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Unprocessed billing runs — review the projected charges before processing.';
    }

    /** @return array<string, mixed> */
    protected function preview(BillingRun $run): array
    {
        return $this->previewCache[$run->id] ??= app(BillingRunService::class)->preview($run);
    }

    /** @param array<string, float> $totals */
    protected function formatTotals(array $totals): string
    {
        if ($totals === []) {
            return '—';
        }

        return collect($totals)
            ->map(fn ($amount, $currency) => Currencies::format($amount, $currency))
            ->implode('  •  ');
    }
}
