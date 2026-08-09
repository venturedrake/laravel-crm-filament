<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Users;

use App\Models\User;
use BackedEnum;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Http\Rules\AssignableRole;
use VentureDrake\LaravelCrm\Models\Role;
use VentureDrake\LaravelCrm\Models\Team;
use VentureDrake\LaravelCrmFilament\Concerns\ContactFieldsSchema;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Resources\Users\Pages\CreateUser;
use VentureDrake\LaravelCrmFilament\Resources\Users\Pages\EditUser;
use VentureDrake\LaravelCrmFilament\Resources\Users\Pages\ListUsers;
use VentureDrake\LaravelCrmFilament\Resources\Users\Pages\ViewUser;

class UserResource extends Resource
{
    protected static ?string $slug = 'users';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-users';

    protected static string | \UnitEnum | null $navigationGroup = 'Contacts';

    protected static ?int $navigationSort = 50;

    public static function getNavigationBadge(): ?string
    {
        $model = static::getModel();
        $count = $model::query()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'gray';
    }

    public static function getModel(): string
    {
        // Defer to host's configured user model so this works even when
        // the host extends App\Models\User from elsewhere.
        return config('auth.providers.users.model', User::class);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
            ]),

            Forms\Components\TextInput::make('password')
                ->password()
                ->revealable()
                ->dehydrateStateUsing(fn ($state) => filled($state) ? bcrypt($state) : null)
                ->dehydrated(fn ($state) => filled($state))
                ->required()
                ->minLength(8)
                ->visibleOn('create')
                ->columnSpanFull(),

            Forms\Components\Toggle::make('crm_access')
                ->label(__('laravel-crm-filament::labels.misc.crm_access'))
                ->helperText('Required for the user to reach the Filament panel.')
                ->default(true),

            // No ->dehydrated(false) on either of these. EditRecord::save()
            // dehydrates the form *before* mutateFormDataBeforeSave() reads the
            // state, so a non-dehydrated key is Arr::forget'd and arrives as
            // null — which made afterSave() call syncRoles([]) and strip the
            // role off every user that was edited. Both keys are unset() in
            // mutateFormDataBefore{Create,Save} instead, so nothing tries to
            // write them as columns. See UserRoleRetainedTest.
            Forms\Components\Select::make('role_id')
                ->label(__('laravel-crm-filament::labels.fields.role'))
                // Role::assignableBy() is core's single predicate for "roles this
                // caller may hand out". The rule object shares it with the
                // dropdown so the options offered and the values accepted can
                // never diverge — a tampered payload cannot escalate to Owner.
                ->options(fn () => Role::assignableBy()->orderBy('name')->pluck('name', 'id'))
                ->rules([new AssignableRole])
                ->searchable()
                ->preload()
                ->columnSpanFull(),

            Forms\Components\Select::make('crm_team_ids')
                ->label(__('laravel-crm-filament::labels.sections.teams'))
                ->multiple()
                ->options(fn () => Team::query()->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->preload()
                // The CRM teams *module* (a grouping entity), not the
                // laravel-crm.teams multi-tenancy flag — same gate
                // CrmTeamResource is registered behind.
                ->visible(fn (): bool => LaravelCrmPlugin::get()->isModuleEnabled('teams'))
                ->columnSpanFull(),

            ContactFieldsSchema::phonesRepeater(),
            ContactFieldsSchema::addressesRepeater(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('laravel-crm-filament::labels.fields.name'))
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('laravel-crm-filament::labels.fields.email'))
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('email_verified_at')
                    ->label(__('laravel-crm-filament::labels.fields.verified'))
                    ->date()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('crm_access')
                    ->label(__('laravel-crm-filament::labels.fields.crm_access'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label(__('laravel-crm-filament::labels.fields.role'))
                    ->state(fn ($record) => $record->roles->first()?->name),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('laravel-crm-filament::labels.fields.created'))
                    ->since(),
                Tables\Columns\TextColumn::make('last_online_at')
                    ->label(__('laravel-crm-filament::labels.fields.last_online'))
                    ->since()
                    ->placeholder('Never'),
            ])
            ->defaultSort('name')
            ->filters([
                // Mirrors core's UserIndex: whereHas('roles', crm_role = 1 AND
                // roles.id IN (...)). The closure scopes both the options list
                // and the whereHas, so a host's own non-CRM Spatie roles never
                // appear here and never widen the filter.
                //
                // Role::crm(), not assignableBy(): that predicate governs which
                // roles a caller may hand *out*. Filtering is a read, the role
                // is already on screen in the roles.name column, and hiding
                // Owner from the dropdown would only stop a Manager narrowing a
                // list they can already see in full.
                Tables\Filters\SelectFilter::make('roles')
                    ->label(__('laravel-crm-filament::labels.fields.role'))
                    ->multiple()
                    ->relationship('roles', 'name', fn (Builder $query) => $query->where('crm_role', 1))
                    ->searchable()
                    ->preload(),

                Tables\Filters\TernaryFilter::make('crm_access')->label(__('laravel-crm-filament::labels.misc.has_crm_access')),
            ])
            ->recordActions([
                Actions\ViewAction::make()->button()->hiddenLabel(),
                Actions\EditAction::make()->button()->hiddenLabel(),
                Actions\DeleteAction::make()->button()->hiddenLabel(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([Actions\DeleteBulkAction::make()]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('laravel-crm-filament::labels.sections.details'))
                ->schema([
                    TextEntry::make('name')
                        ->label(__('laravel-crm-filament::labels.fields.name')),

                    TextEntry::make('email')
                        ->label(__('laravel-crm-filament::labels.fields.email')),

                    TextEntry::make('email_verified_at')
                        ->label(__('laravel-crm-filament::labels.fields.verified'))
                        ->dateTime()
                        ->placeholder('—'),

                    TextEntry::make('crm_access')
                        ->label(__('laravel-crm-filament::labels.fields.crm_access'))
                        ->formatStateUsing(fn ($state): string => $state ? 'Yes' : 'No'),

                    TextEntry::make('role')
                        ->label(__('laravel-crm-filament::labels.fields.role'))
                        ->state(fn ($record) => $record?->roles?->first()?->name),

                    TextEntry::make('created_at')
                        ->label(__('laravel-crm-filament::labels.fields.created'))
                        ->since(),

                    TextEntry::make('last_online_at')
                        ->label(__('laravel-crm-filament::labels.fields.last_online'))
                        ->since()
                        ->placeholder('Never'),
                ]),
        ])->columns(1);
    }

    public static function backToIndexAction(): Action
    {
        return Action::make('backToIndex')
            ->label(__('laravel-crm-filament::labels.actions.back_to_users'))
            ->icon('heroicon-o-arrow-left')
            ->color('gray')
            ->url(static::getUrl('index'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    /**
     * Diff-sync a polymorphic morphMany rowset by id.
     * Update existing rows, create new ones (with a fresh UUID external_id
     * + 'primary' boolean), delete any rows whose id is no longer in the
     * incoming payload. Mirrors the PersonService update*Phones/Addresses
     * pattern for users.
     *
     * @param  Model  $record
     * @param  array<int, array<string, mixed>>  $payload
     * @param  array<int, string>  $columns
     */
    public static function syncMorphRows($record, string $relation, array $payload, string $modelClass, array $columns): void
    {
        if (! method_exists($record, $relation)) {
            return;
        }

        $seenIds = [];

        foreach ($payload as $row) {
            $rowData = [];
            foreach ($columns as $col) {
                $rowData[$col] = $row[$col] ?? null;
            }
            $rowData['primary'] = ! empty($row['primary']);

            if (! empty($row['id']) && $existing = $modelClass::query()->find($row['id'])) {
                $existing->update($rowData);
                $seenIds[] = (int) $existing->id;
            } elseif (! empty(array_filter($rowData, fn ($v) => $v !== null && $v !== false && $v !== ''))) {
                $rowData['external_id'] = (string) Str::uuid();
                $created = $record->{$relation}()->create($rowData);
                $seenIds[] = (int) $created->id;
            }
        }

        foreach ($record->{$relation}()->get() as $existing) {
            if (! in_array((int) $existing->id, $seenIds, true)) {
                $existing->delete();
            }
        }
    }
}
