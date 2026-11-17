<?php

namespace VentureDrake\LaravelCrmFilament\Resources\PipelineStages;

use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Models\PipelineStage;
use VentureDrake\LaravelCrm\Models\PipelineStageProbability;
use VentureDrake\LaravelCrmFilament\Concerns\UsesExternalIdRouting;
use VentureDrake\LaravelCrmFilament\Resources\PipelineStages\Pages\CreatePipelineStage;
use VentureDrake\LaravelCrmFilament\Resources\PipelineStages\Pages\EditPipelineStage;
use VentureDrake\LaravelCrmFilament\Resources\PipelineStages\Pages\ListPipelineStages;
use VentureDrake\LaravelCrmFilament\Resources\PipelineStages\Pages\ViewPipelineStage;

class PipelineStageResource extends Resource
{
    use UsesExternalIdRouting;

    protected static ?string $model = PipelineStage::class;

    protected static ?string $slug = 'pipeline-stages';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-bars-3-bottom-left';

    protected static string | \UnitEnum | null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                Forms\Components\Select::make('pipeline_id')
                    ->label(__('laravel-crm-filament::labels.sales.pipeline'))
                    ->options(fn () => static::pipelineOptions())
                    // Core 2.7.0 (#138): preselect the option the select shows
                    // first, so an untouched form saves.
                    ->default(fn () => static::pipelineOptions()->keys()->first())
                    ->required()
                    ->searchable(),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
            ]),
            Grid::make(2)->schema([
                // A new stage is appended by core's PipelineStageService.
                Forms\Components\TextInput::make('order')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->hiddenOn('create'),
                Forms\Components\ColorPicker::make('color'),
            ]),
            Forms\Components\Select::make('pipeline_stage_probability_id')
                ->label(__('laravel-crm-filament::labels.sales.probability'))
                ->options(fn () => PipelineStageProbability::query()->orderBy('percent')->get()->mapWithKeys(fn ($p) => [$p->id => $p->name . ' (' . $p->percent . '%)']))
                ->searchable()
                ->preload(),
            Forms\Components\Textarea::make('description')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    /**
     * @return Collection<int|string, string>
     */
    public static function pipelineOptions(): Collection
    {
        return Pipeline::query()->orderBy('name')->orderBy('id')->pluck('name', 'id');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('pipeline.name')
                    ->label(__('laravel-crm-filament::labels.sales.attached_to'))
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                Actions\ViewAction::make()->button()->hiddenLabel(),
                Actions\EditAction::make()->button()->hiddenLabel(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function backToIndexAction(): Actions\Action
    {
        return Actions\Action::make('backToIndex')
            ->label(__('laravel-crm-filament::labels.actions.back_to_pipeline_stages'))
            ->icon('heroicon-o-arrow-left')
            ->color('gray')
            ->url(static::getUrl('index'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPipelineStages::route('/'),
            'create' => CreatePipelineStage::route('/create'),
            'view' => ViewPipelineStage::route('/{record}'),
            'edit' => EditPipelineStage::route('/{record}/edit'),
        ];
    }
}
