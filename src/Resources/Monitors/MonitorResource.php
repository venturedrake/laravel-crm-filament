<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Monitors;

use BackedEnum;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Jobs\RunMonitorCheck;
use VentureDrake\LaravelCrm\Models\Monitor;
use VentureDrake\LaravelCrm\Models\MonitorCheck;
use VentureDrake\LaravelCrmFilament\Concerns\UsesExternalIdRouting;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\RelationManagers\MonitorChecksRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Monitors\Pages\CreateMonitor;
use VentureDrake\LaravelCrmFilament\Resources\Monitors\Pages\EditMonitor;
use VentureDrake\LaravelCrmFilament\Resources\Monitors\Pages\ListMonitors;
use VentureDrake\LaravelCrmFilament\Resources\Monitors\Pages\ViewMonitor;

class MonitorResource extends Resource
{
    use UsesExternalIdRouting;

    protected static ?string $model = Monitor::class;

    protected static ?string $slug = 'monitors';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-signal';

    protected static ?int $navigationSort = 90;

    public static function getNavigationGroup(): ?string
    {
        return LaravelCrmPlugin::get()->getNavigationGroup() ?? 'Monitoring';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Monitor::query()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'primary';
    }

    public static function isEnabled(): bool
    {
        return LaravelCrmPlugin::get()->isModuleEnabled('monitoring');
    }

    public static function form(Schema $schema): Schema
    {
        // Mirrors the 2-col create/edit monitor form: each row pairs a left
        // and right field. Order: URL / Friendly name, Description / Method,
        // Expected status code / Run check every, Minutes downtime /
        // Performance threshold, Owner / Active. The `type` column is
        // captured as a Hidden default of 'https' since the URL field shows
        // the protocol via a prefix instead of a visible Select.
        return $schema->components([
            Section::make(__('laravel-crm-filament::labels.sections.monitor_settings'))
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Forms\Components\Hidden::make('type')->default('https'),

                    Forms\Components\TextInput::make('url')
                        ->label(__('laravel-crm-filament::labels.fields.website_url'))
                        ->prefix('https://')
                        ->placeholder('example.com')
                        ->required()
                        ->maxLength(1024),

                    Forms\Components\TextInput::make('name')
                        ->label(__('laravel-crm-filament::labels.fields.friendly_name'))
                        ->helperText(__('laravel-crm-filament::labels.sales.friendly_name_helper'))
                        ->maxLength(255),

                    Forms\Components\Textarea::make('description')
                        ->label(__('laravel-crm-filament::labels.fields.description'))
                        ->rows(4),

                    Forms\Components\Select::make('method')
                        ->label(__('laravel-crm-filament::labels.fields.method'))
                        ->helperText(__('laravel-crm-filament::labels.sales.method_helper'))
                        ->options([
                            'GET' => 'GET',
                            'POST' => 'POST',
                            'HEAD' => 'HEAD',
                            'PUT' => 'PUT',
                            'DELETE' => 'DELETE',
                            'PATCH' => 'PATCH',
                        ])
                        ->default('GET')
                        ->required(),

                    Forms\Components\TextInput::make('expected_status_code')
                        ->label(__('laravel-crm-filament::labels.fields.expected_status_code'))
                        ->numeric()
                        ->minValue(100)
                        ->maxValue(599)
                        ->default(200),

                    Forms\Components\TextInput::make('interval')
                        ->label(__('laravel-crm-filament::labels.sales.run_check_every'))
                        ->suffix(__('laravel-crm-filament::labels.sales.minutes'))
                        ->numeric()
                        ->minValue(1)
                        ->default(5),

                    Forms\Components\TextInput::make('downtime_minutes_before_alert')
                        ->label(__('laravel-crm-filament::labels.sales.minutes_downtime_before_alert'))
                        ->suffix(__('laravel-crm-filament::labels.sales.minutes'))
                        // Core reads monitoring.perf_alert_rate_limit_minutes and
                        // monitoring.recovered_alert_rate_limit_minutes with
                        // inline defaults but does not ship them in
                        // config/laravel-crm.php, so they are invisible to an
                        // operator unless something says so. Filed upstream.
                        ->helperText(__('laravel-crm-filament::labels.sales.downtime_helper') . ' ' . __('laravel-crm-filament::labels.sales.alert_rate_limit_helper'))
                        ->numeric()
                        ->minValue(0)
                        ->default(5),

                    Forms\Components\TextInput::make('perf_threshold_ms')
                        ->label(__('laravel-crm-filament::labels.sales.performance_threshold'))
                        ->suffix('ms')
                        ->helperText(__('laravel-crm-filament::labels.sales.threshold_helper'))
                        ->numeric()
                        ->minValue(0)
                        ->default(3500),

                    Forms\Components\Select::make('user_owner_id')
                        ->label(__('laravel-crm-filament::labels.fields.owner'))
                        ->relationship('ownerUser', 'name')
                        ->searchable()
                        ->preload(),

                    Forms\Components\Toggle::make('is_active')
                        ->label(__('laravel-crm-filament::labels.fields.active'))
                        ->default(true)
                        ->inline(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('monitor_id')
                    ->label(__('laravel-crm-filament::labels.fields.number'))
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('name')
                    ->label(__('laravel-crm-filament::labels.fields.name'))
                    ->state(fn (?Model $record): string => $record?->displayName() ?? '')
                    ->sortable()
                    ->searchable(query: fn ($query, string $search) => $query->where(
                        fn ($q) => $q->where('name', 'like', "%{$search}%")
                            ->orWhere('url', 'like', "%{$search}%")
                            ->orWhere('monitor_id', 'like', "%{$search}%")
                    )),

                Tables\Columns\TextColumn::make('last_status')
                    ->label(__('laravel-crm-filament::labels.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : '—')
                    ->color(fn (?string $state): string => match ($state) {
                        'up' => 'success',
                        'down' => 'danger',
                        'slow' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\ViewColumn::make('performance')
                    ->label(__('laravel-crm-filament::labels.fields.performance'))
                    ->view('laravel-crm-filament::monitors.performance-bars'),

                Tables\Columns\TextColumn::make('last_response_time')
                    ->label(__('laravel-crm-filament::labels.fields.response_time'))
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? "{$state} ms" : '—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_checked_at')
                    ->label(__('laravel-crm-filament::labels.fields.last_checked'))
                    ->formatStateUsing(fn ($state): string => $state ? $state->format('Y-m-d H:i') : '—')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label(__('laravel-crm-filament::labels.fields.type'))
                    ->options([
                        'http' => 'HTTP',
                        'https' => 'HTTPS',
                    ]),

                Tables\Filters\SelectFilter::make('last_status')
                    ->label(__('laravel-crm-filament::labels.fields.last_status'))
                    ->options([
                        'up' => 'Up',
                        'down' => 'Down',
                        'slow' => 'Slow',
                    ]),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('laravel-crm-filament::labels.fields.is_active')),

                Tables\Filters\TernaryFilter::make('ssl_enabled')
                    ->label(__('laravel-crm-filament::labels.fields.ssl_enabled')),

                Tables\Filters\SelectFilter::make('user_owner_id')
                    ->label(__('laravel-crm-filament::labels.fields.owner'))
                    ->multiple()
                    ->relationship('ownerUser', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                Actions\ViewAction::make()
                    ->button()
                    ->hiddenLabel(),
                Actions\EditAction::make()
                    ->button()
                    ->hiddenLabel(),
                Actions\DeleteAction::make()
                    ->button()
                    ->hiddenLabel()
                    ->requiresConfirmation(),
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
            MonitorChecksRelationManager::class,
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['monitor_id', 'name', 'url', 'host'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var Monitor $record */
        return (string) ($record->name ?? $record->host ?? $record->url ?? $record->getKey());
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Monitor $record */
        return array_filter([
            'ID' => $record->monitor_id,
            'URL' => $record->url,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMonitors::route('/'),
            'create' => CreateMonitor::route('/create'),
            'view' => ViewMonitor::route('/{record}'),
            'edit' => EditMonitor::route('/{record}/edit'),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        // Mirrors core CRM's monitors/monitor-show layout: a 2-column Details
        // card showing exactly the 8 fields core surfaces, plus a conditional
        // SSL section preserved from the previous infolist. The 4 quick stat
        // cards (status / response time / status code / last checked) and the
        // response-time chart are wired as ViewMonitor header widgets, not
        // here, so the infolist tells only the "configuration" story.
        return $schema->components([
            Section::make(__('laravel-crm-filament::labels.sections.details'))
                ->columns(2)
                ->schema([
                    TextEntry::make('type')
                        ->label(__('laravel-crm-filament::labels.fields.type'))
                        ->formatStateUsing(fn (?string $state): string => $state ? strtoupper($state) : '')
                        ->placeholder('—'),

                    TextEntry::make('method')
                        ->label(__('laravel-crm-filament::labels.fields.method'))
                        ->placeholder('—'),

                    TextEntry::make('expected_status_code')
                        ->label(__('laravel-crm-filament::labels.fields.expected_status_code'))
                        ->placeholder('—'),

                    TextEntry::make('interval')
                        ->label(__('laravel-crm-filament::labels.sales.run_check_every'))
                        ->state(fn (?Monitor $record): string => $record?->interval !== null
                            ? $record->interval . ' ' . __('laravel-crm-filament::labels.sales.minutes')
                            : '—'),

                    TextEntry::make('downtime_minutes_before_alert')
                        ->label(__('laravel-crm-filament::labels.sales.minutes_downtime_before_alert'))
                        ->state(fn (?Monitor $record): string => $record?->downtime_minutes_before_alert !== null
                            ? $record->downtime_minutes_before_alert . ' ' . __('laravel-crm-filament::labels.sales.minutes')
                            : '—'),

                    TextEntry::make('perf_threshold_ms')
                        ->label(__('laravel-crm-filament::labels.sales.performance_threshold'))
                        ->state(fn (?Monitor $record): string => $record?->perf_threshold_ms !== null
                            ? $record->perf_threshold_ms . ' ms'
                            : '—'),

                    // core 2.4.0 rate-limits performance and recovery alerts
                    // off these two stamps. Without them on screen, "why did I
                    // not get an alert?" has no answer in the UI.
                    TextEntry::make('perf_notified_at')
                        ->label(__('laravel-crm-filament::labels.fields.perf_notified_at'))
                        ->dateTime()
                        ->since()
                        ->placeholder('—'),

                    TextEntry::make('recovered_notified_at')
                        ->label(__('laravel-crm-filament::labels.fields.recovered_notified_at'))
                        ->dateTime()
                        ->since()
                        ->placeholder('—'),

                    TextEntry::make('ownerUser.name')
                        ->label(__('laravel-crm-filament::labels.fields.owner'))
                        ->placeholder(__('laravel-crm-filament::labels.misc.unallocated')),

                    TextEntry::make('is_active')
                        ->label(__('laravel-crm-filament::labels.fields.active'))
                        ->state(fn (?Monitor $record): string => $record?->is_active
                            ? __('laravel-crm::lang.yes')
                            : __('laravel-crm::lang.no')),
                ]),

            Section::make(__('laravel-crm-filament::labels.sections.ssl'))
                ->columns(2)
                ->schema([
                    TextEntry::make('ssl_status')
                        ->label(__('laravel-crm-filament::labels.fields.ssl_status'))
                        ->placeholder('—'),

                    TextEntry::make('ssl_issuer')
                        ->label(__('laravel-crm-filament::labels.fields.ssl_issuer'))
                        ->placeholder('—'),

                    TextEntry::make('ssl_expires_at')
                        ->label(__('laravel-crm-filament::labels.fields.ssl_expires_at'))
                        ->dateTime()
                        ->placeholder('—'),

                    TextEntry::make('ssl_last_checked_at')
                        ->label(__('laravel-crm-filament::labels.fields.ssl_last_checked_at'))
                        ->dateTime()
                        ->since()
                        ->placeholder('—'),
                ])
                ->hidden(fn (?Monitor $record): bool => ! ($record?->ssl_enabled ?? false)),
        ])->columns(1);
    }

    public static function backToIndexAction(): Action
    {
        return Action::make('backToIndex')
            ->label(__('laravel-crm-filament::labels.actions.back_to_monitors'))
            ->icon('heroicon-o-arrow-left')
            ->color('gray')
            ->url(static::getUrl('index'));
    }

    public static function runCheckNowAction(): Action
    {
        return Action::make('runCheckNow')
            ->label(__('laravel-crm-filament::labels.actions.run_check_now'))
            ->icon('heroicon-o-bolt')
            ->color('primary')
            ->requiresConfirmation()
            ->action(function (Monitor $record): void {
                // dispatchSync is used (not dispatch) so the check runs immediately in the
                // current request regardless of the host's queue connection. Without it,
                // hosts on a database/redis queue would see the action return before the
                // check ran, and the admin would not get an immediate result row.
                RunMonitorCheck::dispatchSync($record->id);

                Notification::make()
                    ->title(__('laravel-crm-filament::labels.actions.run_check_now'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Compute the last-7-days uptime response-time sparkline for a Monitor.
     * Mirrors core CRM's MonitorIndex::performanceData() shape: an array of
     * 7 ints (oldest -> newest) where each value is the average response_time
     * for that day's uptime checks (0 when no data).
     *
     * @return array<int, int>
     */
    public static function performanceBars(Monitor $record): array
    {
        $start = Carbon::now()->subDays(6)->startOfDay();

        $rows = MonitorCheck::query()
            ->where('monitor_id', $record->id)
            ->where('type', 'uptime')
            ->whereNotNull('response_time')
            ->where('checked_at', '>=', $start)
            ->get(['response_time', 'checked_at']);

        $buckets = array_fill(0, 7, ['sum' => 0, 'count' => 0]);

        foreach ($rows as $row) {
            $dayIndex = (int) floor(($row->checked_at->getTimestamp() - $start->getTimestamp()) / 86400);

            if ($dayIndex < 0 || $dayIndex > 6) {
                continue;
            }

            $buckets[$dayIndex]['sum'] += (int) $row->response_time;
            $buckets[$dayIndex]['count']++;
        }

        return array_map(
            fn ($b) => $b['count'] === 0 ? 0 : (int) round($b['sum'] / $b['count']),
            $buckets,
        );
    }
}
