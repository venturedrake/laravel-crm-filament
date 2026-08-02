<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Customers;

use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\Customer;
use VentureDrake\LaravelCrmFilament\Concerns\HasEncryptedGlobalSearch;
use VentureDrake\LaravelCrmFilament\Concerns\HasEncryptedSearch;
use VentureDrake\LaravelCrmFilament\Concerns\UsesExternalIdRouting;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\RelationManagers\FilesRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Customers\Pages\CreateCustomer;
use VentureDrake\LaravelCrmFilament\Resources\Customers\Pages\EditCustomer;
use VentureDrake\LaravelCrmFilament\Resources\Customers\Pages\ListCustomers;
use VentureDrake\LaravelCrmFilament\Resources\Customers\Pages\ViewCustomer;

class CustomerResource extends Resource
{
    use HasEncryptedGlobalSearch;
    use UsesExternalIdRouting;

    protected static ?string $model = Customer::class;

    protected static ?string $slug = 'customers';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-identification';

    protected static ?int $navigationSort = 45;

    public static function getNavigationGroup(): ?string
    {
        return LaravelCrmPlugin::get()->getNavigationGroup() ?? 'Contacts';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255),

            Forms\Components\Select::make('user_owner_id')
                ->label(__('laravel-crm-filament::labels.fields.owner'))
                ->relationship('ownerUser', 'name')
                ->searchable()
                ->preload(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $encrypted = config('laravel-crm.encrypt_db_fields', false);

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->sortable(! $encrypted)
                    ->searchable(! $encrypted)
                    ->limit(60),

                Tables\Columns\TextColumn::make('ownerUser.name')
                    ->label(__('laravel-crm-filament::labels.fields.owner'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->when(
                $encrypted,
                fn (Table $t) => $t->modifyQueryUsing(
                    HasEncryptedSearch::modifyQuery(fn ($r) => (string) ($r->name ?? ''), ['name'])
                )
            )
            ->recordActions([
                Actions\ViewAction::make()
                    ->button()
                    ->hiddenLabel(),
                Actions\EditAction::make()
                    ->button()
                    ->hiddenLabel(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            FilesRelationManager::class,
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return (string) ($record->name ?? '');
    }

    protected static function crmEncryptedSearchAccessor(): \Closure
    {
        return fn ($r) => (string) ($r->name ?? '');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
