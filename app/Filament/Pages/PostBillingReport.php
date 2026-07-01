<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\BillingRuns\BillingRunResource;
use App\Models\BillingRun;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * Post-billing report: lists all processed (completed) billing runs and what
 * they actually billed. The compulsory record-keeping step after processing.
 */
class PostBillingReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Post-billing';

    protected static ?string $title = 'Post-billing report';

    protected string $view = 'filament.pages.report-table';

    public function table(Table $table): Table
    {
        return $table
            ->query(BillingRun::query()->where('status', 'completed')->latest('run_at'))
            ->emptyStateHeading('No processed runs yet')
            ->emptyStateDescription('Once a billing run is processed it appears here.')
            ->columns([
                TextColumn::make('run_number')->label('Run #')->searchable()->sortable(),
                TextColumn::make('period_month')->label('Period')->date('F Y')->sortable(),
                TextColumn::make('frequency')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => BillingRun::frequencies()[$state] ?? $state),
                TextColumn::make('invoice_count')->label('Invoices')->badge(),
                TextColumn::make('total_billed')
                    ->label('Total billed')
                    ->state(fn (BillingRun $r) => $r->formattedCurrencyTotals()),
                TextColumn::make('run_at')->label('Processed')->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('view_invoices')
                    ->label('View invoices')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->url(fn (BillingRun $r) => BillingRunResource::getUrl('view', ['record' => $r])),
            ]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Processed billing runs and the invoices they raised.';
    }
}
