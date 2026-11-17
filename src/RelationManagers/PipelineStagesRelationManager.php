<?php

namespace VentureDrake\LaravelCrmFilament\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Models\PipelineStage;
use VentureDrake\LaravelCrm\Services\PipelineStageService;
use VentureDrake\LaravelCrmFilament\Concerns\SurfacesServiceValidation;
use VentureDrake\LaravelCrmFilament\Resources\PipelineStages\Pages\CreatePipelineStage;

class PipelineStagesRelationManager extends RelationManager
{
    use SurfacesServiceValidation;

    protected static string $relationship = 'pipelineStages';

    protected static ?string $title = 'Pipeline stages';

    /**
     * The stage order as this table last rendered it. Core 2.7.0's
     * PipelineStageService::reorder() refuses a drag made against an order
     * that has changed since (another tab, another user), rather than
     * silently overwriting it. Locked so the client cannot forge it.
     */
    #[Locked]
    public string $orderingSnapshot = '';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('laravel-crm-filament::labels.sections.pipeline_stages');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('order')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->hidden(),

            Forms\Components\ColorPicker::make('color')
                ->hidden(),

            Forms\Components\Select::make('pipeline_stage_probability_id')
                ->label(__('laravel-crm-filament::labels.sales.probability'))
                ->relationship('pipelineStageProbability', 'name')
                ->searchable()
                ->preload()
                ->hidden(),

            Forms\Components\Textarea::make('description')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order')->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\ColorColumn::make('color')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('pipelineStageProbability.percent')
                    ->label(__('laravel-crm-filament::labels.sales.probability'))
                    ->suffix('%')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('order', 'asc')
            ->reorderable('order')
            ->headerActions([
                CreateAction::make()
                    ->using(fn (array $data, CreateAction $action): PipelineStage => $this->createStage($data, $action)),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Through core 2.7.0's PipelineStageService, which appends the stage
     * after the pipeline's last and refuses past position 127, then the
     * fields it does not write.
     *
     * @param  array<string, mixed>  $data
     */
    protected function createStage(array $data, CreateAction $action): PipelineStage
    {
        try {
            $stage = app(PipelineStageService::class)->create([
                ...$data,
                'pipeline_id' => $this->getOwnerRecord()->getKey(),
            ]);
        } catch (ValidationException $exception) {
            // The modal stays open with what was typed.
            Notification::make()
                ->danger()
                ->title(self::firstServiceValidationMessage($exception))
                ->persistent()
                ->send();

            $action->halt();

            throw $exception; // unreachable: halt() throws
        }

        return CreatePipelineStage::saveExtraStageFields($stage, $data);
    }

    /**
     * A drag goes through core 2.7.0's PipelineStageService::reorder()
     * rather than Filament's bulk update: it renumbers from 1, keeps within
     * the 127 positions the column holds, and refuses an order that changed
     * since this table rendered. A refusal is a warning; the re-render shows
     * the current order.
     *
     * @param  array<int|string, int|string>  $order
     */
    public function reorderTable(array $order, int | string | null $draggedRecordKey = null): void
    {
        if (! $this->getTable()->isReorderable()) {
            return;
        }

        $this->getTable()->callBeforeReordering($order);

        try {
            app(PipelineStageService::class)->reorder(
                (int) $this->getOwnerRecord()->getKey(),
                array_values($order),
                $this->orderingSnapshot,
            );
        } catch (ValidationException $exception) {
            Notification::make()
                ->warning()
                ->title(self::firstServiceValidationMessage($exception))
                ->send();

            return;
        }

        $this->getTable()->callAfterReordering($order);
    }

    public function render(): View
    {
        $this->orderingSnapshot = $this->currentOrderingSnapshot();

        return parent::render();
    }

    protected function currentOrderingSnapshot(): string
    {
        /** @var Pipeline $pipeline */
        $pipeline = $this->getOwnerRecord();

        return app(PipelineStageService::class)->snapshot(
            $pipeline->pipelineStages()->orderBy('order')->orderBy('id')->get(),
        );
    }
}
