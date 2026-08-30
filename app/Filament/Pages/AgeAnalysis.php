<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Jobs\Sage\CaptureAgeing;
use App\Models\AgeingRow;
use App\Models\AgeingSnapshot;
use App\Support\Sage\SageBridge;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Debtor age analysis per service. Reads the most recent snapshot captured from
 * Sage — the cloud app has no Sage connection of its own, so refreshing queues
 * a job for the Sage worker rather than querying inline.
 */
class AgeAnalysis extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Age analysis';

    protected static ?string $title = 'Debtor age analysis';

    protected string $view = 'filament.pages.age-analysis';

    /** Which snapshot is on screen; defaults to the latest. */
    public ?int $snapshotId = null;

    public function mount(): void
    {
        $this->snapshotId = AgeingSnapshot::query()->latest('as_at')->latest('id')->value('id');
    }

    public function getSnapshot(): ?AgeingSnapshot
    {
        return $this->snapshotId === null
            ? null
            : AgeingSnapshot::with('rows')->find($this->snapshotId);
    }

    /** Every snapshot, for the picker. */
    public function getSnapshots(): Collection
    {
        return AgeingSnapshot::query()->latest('as_at')->latest('id')->limit(24)->get();
    }

    /** @return array<string, Collection<int, AgeingRow>> currency => rows */
    public function getRowsByCurrency(): array
    {
        $snapshot = $this->getSnapshot();

        if ($snapshot === null) {
            return [];
        }

        return $snapshot->rows
            ->sortByDesc(fn (AgeingRow $r) => abs((float) $r->total))
            ->groupBy('currency')
            ->all();
    }

    public function buckets(): array
    {
        return AgeingRow::BUCKETS;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh from Sage')
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->modalHeading('Capture a new age analysis')
                ->modalDescription('Queues a read-only capture of every open debtor transaction in Sage, aged into buckets by service. Nothing is written to the council\'s books. Watch progress under Sage → Sage Operations.')
                ->modalSubmitActionLabel('Queue capture')
                ->action(function (): void {
                    SageBridge::queue('capture_ageing', CaptureAgeing::class);

                    Notification::make()
                        ->success()
                        ->title('Age analysis queued')
                        ->body('It will run on the Sage worker. This page shows the new snapshot once it finishes.')
                        ->send();
                }),
            Action::make('print')
                ->label('Print')
                ->icon(Heroicon::OutlinedPrinter)
                ->url(fn () => $this->snapshotId ? route('documents.ageing', ['ageingSnapshot' => $this->snapshotId]) : null)
                ->openUrlInNewTab()
                ->visible(fn () => $this->snapshotId !== null),
            Action::make('download')
                ->label('Download PDF')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->url(fn () => $this->snapshotId ? route('documents.ageing', ['ageingSnapshot' => $this->snapshotId, 'download' => 1]) : null)
                ->visible(fn () => $this->snapshotId !== null),
        ];
    }

    public function getSubheading(): string|Htmlable|null
    {
        $snapshot = $this->getSnapshot();

        return $snapshot === null
            ? 'What each service is owed, and how long it has been outstanding.'
            : sprintf(
                'Balances outstanding as at %s, from %s. Captured %s.',
                $snapshot->as_at->format('j F Y'),
                $snapshot->source_database,
                $snapshot->created_at?->diffForHumans() ?? 'recently',
            );
    }
}
