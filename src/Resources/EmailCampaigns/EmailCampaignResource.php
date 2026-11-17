<?php

namespace VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns;

use BackedEnum;
use Carbon\Carbon;
use Closure;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Placeholder;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use VentureDrake\LaravelCrm\Models\EmailCampaign;
use VentureDrake\LaravelCrm\Models\EmailTemplate;
use VentureDrake\LaravelCrm\Services\EmailCampaignService;
use VentureDrake\LaravelCrm\Support\EmailCampaignAudience;
use VentureDrake\LaravelCrmFilament\Concerns\UsesExternalIdRouting;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\Pages\CreateEmailCampaign;
use VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\Pages\EditEmailCampaign;
use VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\Pages\ListEmailCampaigns;
use VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\Pages\ViewEmailCampaign;
use VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\RelationManagers\ClicksRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\RelationManagers\RecipientsRelationManager;
use VentureDrake\LaravelCrmFilament\Support\EncryptedContactSearch;

class EmailCampaignResource extends Resource
{
    use UsesExternalIdRouting;

    protected static ?string $model = EmailCampaign::class;

    protected static ?string $slug = 'email-campaigns';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-megaphone';

    protected static ?int $navigationSort = 70;

    public const SEND_DRAFT = 'draft';

    public const SEND_NOW = 'now';

    public const SEND_SCHEDULE = 'schedule';

    /**
     * Matches core's HasEmailCampaignAudience::AUDIENCE_SEARCH_LIMIT.
     */
    public const AUDIENCE_SEARCH_LIMIT = 20;

    public static function getNavigationGroup(): ?string
    {
        return LaravelCrmPlugin::get()->getNavigationGroup() ?? 'Marketing';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(255),
                Forms\Components\Select::make('email_template_id')
                    ->label(__('laravel-crm-filament::labels.fields.template'))
                    ->options(fn () => EmailTemplate::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function ($state, $set) {
                        $template = EmailTemplate::find($state);
                        if ($template) {
                            $set('subject', $template->subject);
                            $set('preview_text', $template->preview_text);
                            $set('body', $template->body);
                        }
                    }),
            ]),
            Forms\Components\TextInput::make('subject')->maxLength(255)->columnSpanFull(),
            Forms\Components\TextInput::make('preview_text')
                ->maxLength(255)
                ->helperText('Appears after the subject line in the inbox.')
                ->columnSpanFull(),
            Grid::make(['default' => 1, 'lg' => 4])
                ->columnSpanFull()
                ->schema([
                    Forms\Components\RichEditor::make('body')
                        ->required()
                        ->columnSpan(['default' => 1, 'lg' => 3])
                        ->extraInputAttributes(['style' => 'min-height: 480px']),
                    Placeholder::make('available_placeholders')
                        ->label('Available placeholders')
                        ->columnSpan(['default' => 1, 'lg' => 1])
                        ->content(new HtmlString(static::placeholdersPanelHtml())),
                ]),
            static::audienceSection(),
            Section::make('Send')
                ->heading(ucfirst(__('laravel-crm::lang.send')))
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    // Core 2.7.0: a campaign saves as a draft unless a send
                    // mode is picked. "Send now" confirms on the Create/Save
                    // button (confirmingSendNow()).
                    Forms\Components\Radio::make('send_mode')
                        ->label(ucfirst(__('laravel-crm::lang.send')))
                        ->options([
                            self::SEND_DRAFT => ucfirst(__('laravel-crm::lang.save_as_draft')),
                            self::SEND_NOW => ucfirst(__('laravel-crm::lang.send_now')),
                            self::SEND_SCHEDULE => ucfirst(__('laravel-crm::lang.schedule_send')),
                        ])
                        ->default(self::SEND_DRAFT)
                        ->required()
                        ->live()
                        // Not a model attribute, so the edit form fills it
                        // with null; every visit starts from a draft.
                        ->afterStateHydrated(function (Forms\Components\Radio $component, $state): void {
                            if ($state === null) {
                                $component->state(self::SEND_DRAFT);
                            }
                        })
                        ->afterStateUpdated(function ($state, Set $set): void {
                            if ($state !== self::SEND_SCHEDULE) {
                                $set('scheduled_at', null);
                            }
                        }),
                    static::scheduledAtPicker('scheduled_at')
                        ->label(ucfirst(__('laravel-crm::lang.scheduled_at')))
                        ->visible(fn (Get $get): bool => $get('send_mode') === self::SEND_SCHEDULE)
                        ->required(fn (Get $get): bool => $get('send_mode') === self::SEND_SCHEDULE),
                ]),
        ]);
    }

    /**
     * Who the campaign goes to (core 2.7.0's HasEmailCampaignAudience):
     * everyone subscribed, or the people behind some labels, organizations,
     * pipeline stages or a hand-picked list. Labels and stages are short lists
     * and load in full; people and organizations are searched on the server.
     */
    protected static function audienceSection(): Section
    {
        return Section::make('audience')
            ->heading(ucfirst(__('laravel-crm::lang.audience')))
            ->columns(2)
            ->columnSpanFull()
            ->schema([
                Grid::make(1)
                    ->columnSpan(1)
                    ->schema([
                        Forms\Components\Radio::make('audience_type')
                            ->label(ucfirst(__('laravel-crm::lang.audience')))
                            ->options(fn (): array => collect(EmailCampaignAudience::typeOptions())->pluck('name', 'id')->all())
                            ->default(EmailCampaign::AUDIENCE_ALL)
                            ->required()
                            ->live()
                            // Campaigns from before 2.7.0 have no type: they
                            // went to everyone.
                            ->afterStateHydrated(function (Forms\Components\Radio $component, $state): void {
                                if (blank($state)) {
                                    $component->state(EmailCampaign::AUDIENCE_ALL);
                                }
                            })
                            ->afterStateUpdated(fn (Set $set) => $set('audience_ids', [])),
                        Forms\Components\Select::make('audience_ids')
                            ->label(fn (Get $get): string => ucfirst(EmailCampaignAudience::typeLabel(static::audienceType($get('audience_type')))))
                            ->multiple()
                            ->searchable()
                            ->options(fn (Get $get): array => EmailCampaignAudience::isSearchable($type = static::audienceType($get('audience_type')))
                                ? []
                                : static::audienceOptions($type))
                            ->getSearchResultsUsing(fn (Get $get, ?string $search): array => static::audienceSearchResults(static::audienceType($get('audience_type')), $search))
                            ->getOptionLabelsUsing(fn (Get $get, array $values): array => static::audienceOptions(static::audienceType($get('audience_type')), $values))
                            ->visible(fn (Get $get): bool => static::audienceType($get('audience_type')) !== EmailCampaign::AUDIENCE_ALL)
                            // A filtered type with nothing picked matches
                            // nobody; it never falls back to everyone.
                            ->required(fn (Get $get): bool => static::audienceType($get('audience_type')) !== EmailCampaign::AUDIENCE_ALL)
                            ->live(),
                    ]),
                Placeholder::make('recipient_count')
                    ->label(ucfirst(__('laravel-crm::lang.recipients')))
                    ->columnSpan(1)
                    ->content(fn (Get $get): string => number_format(static::audienceCount($get('audience_type'), $get('audience_ids')))),
            ]);
    }

    public static function audienceType(mixed $type): string
    {
        return is_string($type) && in_array($type, EmailCampaign::AUDIENCE_TYPES, true)
            ? $type
            : EmailCampaign::AUDIENCE_ALL;
    }

    /**
     * The ids that count for $type: none when it targets everyone.
     *
     * @return array<int|string>
     */
    public static function audienceIds(mixed $type, mixed $ids): array
    {
        return static::audienceType($type) === EmailCampaign::AUDIENCE_ALL ? [] : array_values((array) $ids);
    }

    public static function audienceCount(mixed $type, mixed $ids): int
    {
        return EmailCampaignAudience::count(static::audienceType($type), static::audienceIds($type, $ids));
    }

    public static function audienceHasRecipients(mixed $type, mixed $ids): bool
    {
        return EmailCampaignAudience::any(static::audienceType($type), static::audienceIds($type, $ids));
    }

    /**
     * id => name, through core's options() so encrypted names are decrypted.
     *
     * @param  array<int|string>|null  $only
     * @return array<int|string, string>
     */
    public static function audienceOptions(string $type, ?array $only = null): array
    {
        return collect(EmailCampaignAudience::options($type, $only))
            ->mapWithKeys(fn (array $option): array => [$option['id'] => $option['name']])
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    public static function audienceSearchResults(string $type, ?string $search): array
    {
        $search = trim((string) $search);

        return match ($type) {
            EmailCampaign::AUDIENCE_PEOPLE => EncryptedContactSearch::personOptions($search, self::AUDIENCE_SEARCH_LIMIT),
            EmailCampaign::AUDIENCE_ORGANIZATIONS => EncryptedContactSearch::organizationOptions($search, self::AUDIENCE_SEARCH_LIMIT),
            EmailCampaign::AUDIENCE_ALL => [],
            default => array_filter(
                static::audienceOptions($type),
                fn (string $name): bool => $search === '' || mb_stripos($name, $search) !== false,
            ),
        };
    }

    /**
     * Danger notification and halt when a non-draft save has nobody to send
     * to. Core 2.7.0 checks before anything is written, so an empty "Send
     * now" leaves no draft behind.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws Halt
     */
    public static function refuseEmptyAudience(array $data): void
    {
        if (($data['send_mode'] ?? self::SEND_DRAFT) === self::SEND_DRAFT) {
            return;
        }

        if (! static::audienceHasRecipients($data['audience_type'] ?? null, $data['audience_ids'] ?? [])) {
            static::notifyEmptyAudience();

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }

    public static function notifyEmptyAudience(): void
    {
        Notification::make()
            ->danger()
            ->title(ucfirst(__('laravel-crm::lang.no_recipients_in_audience')))
            ->send();
    }

    /**
     * After the campaign is saved: "now" and "schedule" go through core's
     * EmailCampaignService::schedule(), which materialises the recipients.
     *
     * @param  array<string, mixed>  $data
     */
    public static function scheduleAfterSave(EmailCampaign $campaign, array $data): void
    {
        $sendAt = match ($data['send_mode'] ?? self::SEND_DRAFT) {
            self::SEND_NOW => null,
            self::SEND_SCHEDULE => (string) $data['scheduled_at'],
            default => false,
        };

        if ($sendAt !== false) {
            app(EmailCampaignService::class)->schedule($campaign, $sendAt);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function savedMessage(array $data): string
    {
        return match ($data['send_mode'] ?? self::SEND_DRAFT) {
            self::SEND_NOW => ucfirst(__('laravel-crm::lang.campaign_sending')),
            self::SEND_SCHEDULE => ucfirst(__('laravel-crm::lang.campaign_scheduled')),
            default => ucfirst(__('laravel-crm::lang.campaign_saved_as_draft')),
        };
    }

    /**
     * Put the page's Create/Save button behind core's `confirm_send_now`
     * modal when "Send now" is picked and the audience has someone in it.
     * An empty audience skips the modal and is refused by the save.
     *
     * @param  Closure(): array<string, mixed>  $state  the form's raw state
     * @param  Closure(): void  $save
     */
    public static function confirmingSendNow(Action $action, Closure $state, Closure $save): Action
    {
        $confirms = function () use ($state): bool {
            $data = $state();

            return ($data['send_mode'] ?? null) === self::SEND_NOW
                && static::audienceHasRecipients($data['audience_type'] ?? null, $data['audience_ids'] ?? []);
        };

        return $action
            ->submit(null)
            // Both, or the custom heading alone would open a modal for a
            // plain draft save too.
            ->modal($confirms)
            ->requiresConfirmation($confirms)
            ->modalHeading(ucfirst(__('laravel-crm::lang.send_now')))
            ->modalDescription(function () use ($state): string {
                $data = $state();
                $count = static::audienceCount($data['audience_type'] ?? null, $data['audience_ids'] ?? []);

                return trans_choice('laravel-crm::lang.confirm_send_now', $count, ['count' => number_format($count)]);
            })
            ->modalSubmitActionLabel(ucfirst(__('laravel-crm::lang.send_now')))
            ->action(fn () => $save());
    }

    /**
     * A send time in the future, read in the CRM's timezone: that is how
     * EmailCampaignService::schedule() reads it.
     */
    public static function scheduledAtPicker(string $name): Forms\Components\DateTimePicker
    {
        return Forms\Components\DateTimePicker::make($name)
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (blank($value)) {
                    return;
                }

                $timezone = (string) (app('laravel-crm.settings')->get('timezone') ?: 'UTC');

                try {
                    $future = Carbon::parse((string) $value, $timezone)->isFuture();
                } catch (\Throwable) {
                    $future = false;
                }

                if (! $future) {
                    $fail(__('validation.after', ['date' => 'now']));
                }
            });
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Details')->heading(__('laravel-crm-filament::labels.sections.details'))
                ->key('campaign_details')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextEntry::make('name')
                        ->label(__('laravel-crm-filament::labels.fields.name')),
                    TextEntry::make('campaign_id')
                        ->label(__('laravel-crm-filament::labels.fields.number')),
                    TextEntry::make('subject')
                        ->label(__('laravel-crm-filament::labels.fields.subject'))
                        ->columnSpanFull(),
                    TextEntry::make('preview_text')
                        ->columnSpanFull(),
                    TextEntry::make('status')
                        ->label(__('laravel-crm-filament::labels.fields.status'))
                        ->badge(),
                    TextEntry::make('audience')
                        ->label(ucfirst(__('laravel-crm::lang.audience')))
                        ->state(fn (EmailCampaign $record): string => EmailCampaignAudience::describe($record)),
                    TextEntry::make('scheduled_at')
                        ->label(__('laravel-crm-filament::labels.campaign.schedule_for'))
                        ->state(function (EmailCampaign $record): ?string {
                            if (! $record->scheduled_at) {
                                return null;
                            }

                            $formatted = $record->scheduled_at->format('M j, Y g:i A');

                            return $record->timezone
                                ? $formatted . ' (' . $record->timezone . ')'
                                : $formatted;
                        })
                        ->placeholder('—'),
                    TextEntry::make('sent_at')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('template.name')
                        ->label(__('laravel-crm-filament::labels.fields.template'))
                        ->placeholder('—'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('campaign_id')
                    ->label(__('laravel-crm-filament::labels.fields.number'))
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->sortable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('subject', 'like', "%{$search}%")
                            ->orWhere('campaign_id', 'like', "%{$search}%");
                    }),
                Tables\Columns\TextColumn::make('subject')
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->subject)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'scheduled' => 'warning',
                        'sending' => 'info',
                        'sent' => 'success',
                        'cancelled' => 'gray',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('total_recipients')
                    ->label(__('laravel-crm-filament::labels.campaign.recipients'))
                    ->numeric()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('scheduled_at')
                    ->since()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('sent_at')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'scheduled' => 'Scheduled',
                        'sending' => 'Sending',
                        'sent' => 'Sent',
                        'cancelled' => 'Cancelled',
                        'failed' => 'Failed',
                    ]),
            ])
            ->recordActions([
                Actions\ViewAction::make()->button()->hiddenLabel(),
                Actions\EditAction::make()->visible(fn ($record) => $record->isEditable())->button()->hiddenLabel(),
                Actions\DeleteAction::make()->button()->hiddenLabel()->requiresConfirmation(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([Actions\DeleteBulkAction::make()]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RecipientsRelationManager::class,
            ClicksRelationManager::class,
        ];
    }

    protected static function placeholdersPanelHtml(): string
    {
        $tokens = [
            'first_name' => 'Recipient first name',
            'last_name' => 'Recipient last name',
            'full_name' => 'Recipient full name',
            'email' => 'Recipient email address',
            'company_name' => 'Your company name',
        ];

        $hint = 'Click to copy. Insert into the subject or body and they will be replaced when the email is sent.';

        $out = '<div style="font-size:0.75rem;color:#6b7280;margin-bottom:0.75rem;">' . e($hint) . '</div>';
        $out .= '<div style="display:flex;flex-direction:column;gap:0.5rem;">';
        foreach ($tokens as $key => $description) {
            $token = '{' . $key . '}';
            $out .= '<button type="button" '
                . 'x-data="{ copied: false }" '
                . 'x-on:click="navigator.clipboard.writeText(\'' . e($token) . '\').then(() => { copied = true; setTimeout(() => copied = false, 1500); })" '
                . 'title="' . e($description) . '" '
                . 'style="display:block;width:100%;text-align:left;padding:0.25rem 0.5rem;border-radius:0.25rem;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:0.75rem;background:#f3f4f6;color:#1f2937;border:1px solid #e5e7eb;cursor:pointer;">'
                . '<span x-show="!copied">' . e($token) . '</span>'
                . '<span x-show="copied" x-cloak style="color:#05b3a9;">' . e($token) . ' &#10003;</span>'
                . '</button>';
        }
        $out .= '</div>';

        return $out;
    }

    public static function backToIndexAction(): Action
    {
        return Action::make('backToIndex')
            ->label(__('laravel-crm-filament::labels.actions.back_to_email_campaigns'))
            ->icon('heroicon-o-arrow-left')
            ->color('gray')
            ->url(static::getUrl('index'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailCampaigns::route('/'),
            'create' => CreateEmailCampaign::route('/create'),
            'view' => ViewEmailCampaign::route('/{record}'),
            'edit' => EditEmailCampaign::route('/{record}/edit'),
        ];
    }
}
