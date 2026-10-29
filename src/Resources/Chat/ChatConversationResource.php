<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Chat;

use BackedEnum;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Ramsey\Uuid\Uuid;
use VentureDrake\LaravelCrm\Models\ChatConversation;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Services\ChatService;
use VentureDrake\LaravelCrmFilament\Concerns\UsesExternalIdRouting;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Resources\Chat\Pages\ListChatConversations;
use VentureDrake\LaravelCrmFilament\Resources\Chat\Pages\ViewChatConversation;

class ChatConversationResource extends Resource
{
    use UsesExternalIdRouting;

    protected static ?string $model = ChatConversation::class;

    protected static ?string $slug = 'chat';

    protected static ?string $recordTitleAttribute = 'chat_id';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?int $navigationSort = 20;

    /**
     * Status enum mirror for the conversations table. Matches the core
     * migration's enum: open | pending | closed. "pending" is the
     * "replied / waiting on visitor" state the inbox uses.
     */
    public const STATUSES = [
        'open' => 'Open',
        'pending' => 'Replied (waiting on visitor)',
        'closed' => 'Closed',
    ];

    public static function getNavigationGroup(): ?string
    {
        return LaravelCrmPlugin::get()->getNavigationGroup() ?? 'Activity';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = ChatConversation::query()->where('status', 'open')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }

    public static function getNavigationLabel(): string
    {
        return 'Chat';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Conversations';
    }

    public static function getModelLabel(): string
    {
        return 'Conversation';
    }

    public static function form(Schema $schema): Schema
    {
        // Conversations are visitor-initiated; this form is unused but Filament expects it.
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // lead backs the convert action's visibility on every row.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['visitor', 'latestMessage', 'lead']))
            ->columns([
                Tables\Columns\TextColumn::make('chat_id')
                    ->label('#')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('visitor_online')
                    ->label('')
                    ->badge()
                    ->state(fn (ChatConversation $record): string => $record->visitor?->isOnline() ? 'Online' : 'Offline')
                    ->color(fn (string $state): string => $state === 'Online' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('visitor.name')
                    ->label(__('laravel-crm-filament::labels.chat.visitor'))
                    ->placeholder('Anonymous')
                    ->searchable(),
                Tables\Columns\TextColumn::make('unread_count')
                    ->label('')
                    ->badge()
                    ->color('danger')
                    ->state(fn (ChatConversation $record): int => $record->unreadForAgents())
                    ->formatStateUsing(fn ($state) => $state > 0 ? $state : null),
                Tables\Columns\TextColumn::make('last_message_preview')
                    ->label(__('laravel-crm-filament::labels.fields.last_message'))
                    ->state(fn (ChatConversation $record): ?string => $record->latestMessage?->body)
                    ->limit(60)
                    ->tooltip(fn (ChatConversation $record): ?string => $record->latestMessage?->body),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'open' => 'success',
                        'pending' => 'warning',
                        'closed' => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('last_message_at')
                    ->label(__('laravel-crm-filament::labels.fields.updated'))
                    ->since()
                    ->sortable(),
                Tables\Columns\TextColumn::make('visitor_last_seen_at')
                    ->label(__('laravel-crm-filament::labels.fields.last_active'))
                    ->state(fn (ChatConversation $record) => $record->visitor?->last_seen_at)
                    ->since()
                    ->placeholder('—'),
            ])
            ->defaultSort('last_message_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(self::STATUSES),
            ])
            ->recordActions([
                Actions\ViewAction::make()
                    ->button()
                    ->hiddenLabel(),
                static::convertToLeadAction()
                    ->button()
                    ->label(__('laravel-crm-filament::labels.actions.convert_to_lead')),
                static::closeAction()->button()->hiddenLabel(),
                Actions\DeleteAction::make()
                    ->button()
                    ->hiddenLabel()
                    ->requiresConfirmation(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\BulkAction::make('markReplied')
                        ->label(__('laravel-crm-filament::labels.actions.bulk_mark_replied'))
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $service = app(ChatService::class);
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->status === 'open') {
                                    $service->markRead($record, 'visitor');
                                    $record->update(['status' => 'pending']);
                                    $count++;
                                }
                            }
                            Notification::make()->title($count . ' conversation(s) marked replied')->success()->send();
                        }),
                    Actions\BulkAction::make('close')
                        ->label(__('laravel-crm-filament::labels.actions.bulk_close'))
                        ->icon('heroicon-o-x-mark')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $service = app(ChatService::class);
                            foreach ($records as $record) {
                                if ($record->status !== 'closed') {
                                    $service->close($record);
                                }
                            }
                            Notification::make()->title($records->count() . ' conversation(s) closed')->success()->send();
                        }),
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function convertToLeadAction(): Action
    {
        return Action::make('convertToLead')
            ->label(__('laravel-crm-filament::labels.actions.convert_to_lead'))
            ->icon('heroicon-o-arrow-right-circle')
            ->color('success')
            // The lead, not lead_id: a conversation whose lead was since deleted
            // can be converted again, as in core 2.6.0.
            ->visible(fn (?ChatConversation $record): bool => $record !== null && ! $record->lead)
            ->action(function (ChatConversation $record): void {
                $visitor = $record->visitor;
                $person = $visitor?->person;

                if (! $person && ($visitor?->name || $visitor?->email)) {
                    $parts = $visitor->name ? explode(' ', trim($visitor->name), 2) : [];
                    $person = Person::create([
                        'external_id' => Uuid::uuid4()->toString(),
                        'first_name' => $parts[0] ?? null,
                        'last_name' => $parts[1] ?? null,
                        'user_created_id' => auth()->id(),
                        'user_updated_id' => auth()->id(),
                    ]);
                    if ($visitor->email) {
                        $person->emails()->create([
                            'address' => $visitor->email,
                            'primary' => true,
                            'user_created_id' => auth()->id(),
                            'user_updated_id' => auth()->id(),
                        ]);
                    }
                    $visitor->update(['person_id' => $person->id]);
                }

                $title = $visitor?->name
                    ? 'Chat with ' . $visitor->name
                    : 'Chat with anonymous visitor';

                $lead = Lead::create([
                    'external_id' => Uuid::uuid4()->toString(),
                    'title' => $title,
                    'person_id' => $person?->id,
                    'lead_status_id' => 1,
                    'user_owner_id' => auth()->id(),
                    'user_created_id' => auth()->id(),
                    'user_updated_id' => auth()->id(),
                ]);

                $record->update(['lead_id' => $lead->id]);

                Notification::make()->title('Converted to lead')->success()->send();
            });
    }

    public static function closeAction(): Action
    {
        return Action::make('close')
            ->label(__('laravel-crm-filament::labels.actions.close'))
            ->icon('heroicon-o-x-mark')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (?ChatConversation $record): bool => $record !== null && $record->status !== 'closed')
            ->action(function (ChatConversation $record): void {
                app(ChatService::class)->close($record);
                Notification::make()->title('Conversation closed')->success()->send();
            });
    }

    public static function backToIndexAction(): Action
    {
        return Action::make('backToIndex')
            ->label(__('laravel-crm-filament::labels.actions.back_to_conversations'))
            ->icon('heroicon-o-arrow-left')
            ->color('gray')
            ->url(static::getUrl('index'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChatConversations::route('/'),
            'view' => ViewChatConversation::route('/{record}'),
        ];
    }
}
