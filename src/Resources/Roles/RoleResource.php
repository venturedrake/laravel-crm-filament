<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Roles;

use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\Permission;
use VentureDrake\LaravelCrm\Models\Role;
use VentureDrake\LaravelCrmFilament\Resources\Roles\Pages\CreateRole;
use VentureDrake\LaravelCrmFilament\Resources\Roles\Pages\EditRole;
use VentureDrake\LaravelCrmFilament\Resources\Roles\Pages\ListRoles;
use VentureDrake\LaravelCrmFilament\Resources\Roles\Pages\ViewRole;

class RoleResource extends Resource
{
    /**
     * Core's Role, not Spatie's.
     *
     * Core registers RolePolicy against VentureDrake\LaravelCrm\Models\Role
     * only, and Gate::getPolicyFor() walks child -> parent, so pointing this at
     * the Spatie parent resolved no policy at all — and Filament allows when it
     * finds none, which left every panel user able to edit roles and grant
     * themselves every permission. RolePolicy::view() also type-hints core's
     * Role, so a Spatie instance would TypeError even once a policy resolved.
     */
    protected static ?string $model = Role::class;

    protected static ?string $slug = 'roles';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-shield-check';

    protected static string | \UnitEnum | null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('description')
                ->maxLength(255),
            Forms\Components\CheckboxList::make('permissions')
                ->relationship('permissions', 'name')
                // Scoped to CRM permissions: the host's own Spatie permissions
                // are none of this screen's business, and offering them here
                // grants them.
                ->options(fn () => Permission::crm()->orderBy('name')->pluck('name', 'id'))
                ->columns(3)
                ->searchable()
                ->bulkToggleable()
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('description')
                    ->limit(60)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('users_count')
                    ->counts('users')
                    ->label(__('laravel-crm-filament::labels.fields.users'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('permissions_count')
                    ->counts('permissions')
                    ->label(__('laravel-crm-filament::labels.fields.permissions'))
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('laravel-crm-filament::labels.fields.created'))
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                Actions\ViewAction::make()
                    ->button()
                    ->hiddenLabel(),
                Actions\EditAction::make()
                    ->button()
                    ->hiddenLabel()
                    ->visible(fn ($record) => ! in_array($record->name, ['Owner', 'Admin'], true)),
                Actions\DeleteAction::make()
                    ->button()
                    ->hiddenLabel()
                    ->requiresConfirmation()
                    ->visible(fn ($record) => ! in_array($record->name, ['Owner', 'Admin'], true)),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([Actions\DeleteBulkAction::make()]),
            ]);
    }

    /**
     * CRM roles only. The host application's own Spatie roles are not this
     * screen's to list, and the crm_role filter this replaces had no query()
     * closure, so it SQL-errored on any install without the column.
     */
    public static function getEloquentQuery(): Builder
    {
        return Role::crm();
    }

    public static function canEdit(Model $record): bool
    {
        return ! in_array($record->name, ['Owner', 'Admin'], true);
    }

    public static function canDelete(Model $record): bool
    {
        return ! in_array($record->name, ['Owner', 'Admin'], true);
    }

    public static function backToIndexAction(): Actions\Action
    {
        return Actions\Action::make('backToIndex')
            ->label(__('laravel-crm-filament::labels.actions.back_to_roles'))
            ->icon('heroicon-o-arrow-left')
            ->color('gray')
            ->url(static::getUrl('index'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'view' => ViewRole::route('/{record}'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
