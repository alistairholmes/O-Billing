<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\AreaType;
use App\Models\ServiceType;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * Guided setup: define the area hierarchy (Province → … → Suburb) and the
 * billable services. Tariffs and customers are then configured per suburb in
 * their own resources. Completing the wizard stamps the municipality as set up.
 */
class SetupWizard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'Setup Wizard';

    protected static ?int $navigationSort = -10;

    protected string $view = 'filament.pages.setup-wizard';

    public ?array $data = [];

    public function mount(): void
    {
        $municipality = Filament::getTenant();

        $this->data = [
            'area_types' => $municipality->areaTypes()->orderBy('level')->get()
                ->map(fn (AreaType $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'is_billing_level' => $t->is_billing_level,
                ])->all(),
            'service_types' => $municipality->serviceTypes()->orderBy('id')->get()
                ->map(fn (ServiceType $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'code' => $s->code,
                    'billing_basis' => $s->billing_basis,
                    'active' => $s->active,
                ])->all(),
        ];

        $this->form->fill($this->data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Wizard::make([
                    Step::make('Area hierarchy')
                        ->description('From province down to suburb')
                        ->icon(Heroicon::OutlinedMapPin)
                        ->schema([
                            Repeater::make('area_types')
                                ->label('Area levels (top to bottom)')
                                ->helperText('Order defines the hierarchy: the first row is the highest level (e.g. Province), the last is the lowest. Mark exactly one as the billing level — that is where tariffs and customers attach (usually Suburb).')
                                ->reorderable()
                                ->orderColumn()
                                ->schema([
                                    Hidden::make('id'),
                                    TextInput::make('name')
                                        ->required()
                                        ->placeholder('e.g. Province, District, Suburb'),
                                    Toggle::make('is_billing_level')
                                        ->label('Billing level (suburb)')
                                        ->helperText('Tariffs & customers attach here')
                                        ->inline(false),
                                ])
                                ->columns(2)
                                ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                                ->defaultItems(4)
                                ->minItems(2)
                                ->addActionLabel('Add level'),
                        ]),
                    Step::make('Service groups')
                        ->description('What you bill for')
                        ->icon(Heroicon::OutlinedRectangleGroup)
                        ->schema([
                            Repeater::make('service_types')
                                ->label('Service groups')
                                ->helperText('Top-level billables (e.g. Property Rates, Refuse). Break them into individual services afterwards in the Services section. Water metering is intentionally left out for now.')
                                ->schema([
                                    Hidden::make('id'),
                                    TextInput::make('name')->required(),
                                    TextInput::make('code')->placeholder('e.g. RATES'),
                                    Select::make('billing_basis')
                                        ->options(ServiceType::billingBases())
                                        ->default(ServiceType::BASIS_FLAT)
                                        ->required(),
                                    Toggle::make('active')->default(true)->inline(false),
                                ])
                                ->columns(2)
                                ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                                ->addActionLabel('Add service group'),
                        ]),
                    Step::make('Finish')
                        ->description('Review & complete')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->schema([
                            \Filament\Schemas\Components\Text::make(
                                'After completing setup, build out your areas (a tree of provinces → … → suburbs), '
                                .'set a tariff for each service in each suburb, capture customers, then run a billing run.'
                            ),
                        ]),
                ])
                    ->submitAction($this->getSubmitAction()),
            ]);
    }

    protected function getSubmitAction(): \Illuminate\Contracts\Support\Htmlable
    {
        return new \Illuminate\Support\HtmlString(
            <<<'HTML'
            <button type="submit" wire:click="save" class="fi-btn fi-btn-size-md fi-color-primary fi-btn-color-primary"
                style="background:rgb(5 150 105);color:#fff;padding:0.5rem 1rem;border-radius:0.5rem;font-weight:600;">
                Complete setup
            </button>
            HTML
        );
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $municipality = Filament::getTenant();

        DB::transaction(function () use ($state, $municipality): void {
            // --- Area types (level = row order) ---
            $keepTypeIds = [];
            foreach (array_values($state['area_types'] ?? []) as $index => $row) {
                $type = AreaType::updateOrCreate(
                    ['id' => $row['id'] ?? null, 'municipality_id' => $municipality->id],
                    [
                        'municipality_id' => $municipality->id,
                        'name' => $row['name'],
                        'level' => $index + 1,
                        'is_billing_level' => (bool) ($row['is_billing_level'] ?? false),
                    ],
                );
                $keepTypeIds[] = $type->id;
            }
            $municipality->areaTypes()->whereNotIn('id', $keepTypeIds)->delete();

            // --- Services ---
            $keepServiceIds = [];
            foreach ($state['service_types'] ?? [] as $row) {
                $service = ServiceType::updateOrCreate(
                    ['id' => $row['id'] ?? null, 'municipality_id' => $municipality->id],
                    [
                        'municipality_id' => $municipality->id,
                        'name' => $row['name'],
                        'code' => $row['code'] ?? null,
                        'billing_basis' => $row['billing_basis'],
                        'active' => (bool) ($row['active'] ?? true),
                    ],
                );
                // The wizard captures groups only; individual services are added
                // later in the Services section. Guarantee a billable default
                // service so tariffs work straight away.
                $service->ensureDefaultService();
                $keepServiceIds[] = $service->id;
            }
            $municipality->serviceTypes()->whereNotIn('id', $keepServiceIds)->delete();

            $municipality->forceFill(['setup_completed_at' => now()])->save();
        });

        Notification::make()
            ->success()
            ->title('Setup complete')
            ->body('Now add your areas and set tariffs per suburb.')
            ->send();

        $this->redirect(\App\Filament\Resources\Areas\AreaResource::getUrl('index', tenant: $municipality));
    }
}
