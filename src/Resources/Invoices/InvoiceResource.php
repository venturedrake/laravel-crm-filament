<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Invoices;

use BackedEnum;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use VentureDrake\LaravelCrm\Mail\SendInvoice;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\InvoiceLine;
use VentureDrake\LaravelCrm\Services\InvoiceService;
use VentureDrake\LaravelCrm\Support\Money;
use VentureDrake\LaravelCrm\Support\RecordSearch;
use VentureDrake\LaravelCrmFilament\Concerns\ExportsCsv;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LeadDealContactSection;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LineItemsRepeater;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\MoneyTotalsRow;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\SalesDetailsSection;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFieldEntries;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFields;
use VentureDrake\LaravelCrmFilament\Concerns\HasEncryptedGlobalSearch;
use VentureDrake\LaravelCrmFilament\Concerns\HasLabels;
use VentureDrake\LaravelCrmFilament\Concerns\HasPdfPreviewAction;
use VentureDrake\LaravelCrmFilament\Concerns\HasPortalLinkAction;
use VentureDrake\LaravelCrmFilament\Concerns\HasPrimaryBulkActions;
use VentureDrake\LaravelCrmFilament\Concerns\HasXeroSyncStateInfolist;
use VentureDrake\LaravelCrmFilament\Concerns\UsesExternalIdRouting;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Models\InvoicePayment;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmActivitiesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmCallsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmFilesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmLunchesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmMeetingsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmNotesRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\CrmTasksRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\CreateInvoice;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\EditInvoice;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\ListInvoices;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\ViewInvoice;
use VentureDrake\LaravelCrmFilament\Resources\Orders\OrderResource;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\OrganizationResource;
use VentureDrake\LaravelCrmFilament\Resources\People\PersonResource;
use VentureDrake\LaravelCrmFilament\Support\CrmMoney;
use VentureDrake\LaravelCrmFilament\Support\CrmPdf;
use VentureDrake\LaravelCrmFilament\Support\DocumentMail;
use VentureDrake\LaravelCrmFilament\Support\MoneyForm;
use VentureDrake\LaravelCrmFilament\Support\PortalMessage;
use VentureDrake\LaravelCrmFilament\Support\PortalUrl;

class InvoiceResource extends Resource
{
    use HasCrmCustomFieldEntries;
    use HasCrmCustomFields;
    use HasEncryptedGlobalSearch;
    use HasLabels;
    use HasPdfPreviewAction;
    use HasPortalLinkAction;
    use HasPrimaryBulkActions;
    use HasXeroSyncStateInfolist;
    use UsesExternalIdRouting;

    protected static ?string $model = Invoice::class;

    protected static ?string $slug = 'invoices';

    protected static ?string $recordTitleAttribute = 'invoice_id';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?int $navigationSort = 52;

    public static function getNavigationGroup(): ?string
    {
        return LaravelCrmPlugin::get()->getNavigationGroup() ?? 'Sales';
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Invoice::query()->whereNull('fully_paid_at')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        $details = SalesDetailsSection::make([
            'title' => false,
            'description' => false,
            'reference' => true,
            'currency' => true,
            'issueDateKey' => 'issue_date',
            'expiryDateKey' => 'due_date',
            'terms' => true,
            'stage' => false,
            'owner' => true,
            'pdfTemplate' => 'invoice',
            'labels' => true,
            'labelsField' => fn () => static::labelsField(),
            'orderLink' => true,
            'customFields' => static::crmCustomFieldsSection(Invoice::class),
        ]);

        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 2])->columnSpanFull()->schema([
                Grid::make(1)
                    ->columnSpan(['lg' => 1])
                    ->schema([$details]),

                Section::make(__('laravel-crm-filament::labels.sections.products'))
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        LineItemsRepeater::products(
                            fkColumn: 'invoice_line_id',
                            priceField: 'unit_price',
                            drawdownModel: InvoiceLine::class,
                            drawdownRelation: 'invoice',
                            drawdownKey: 'invoice_line_id',
                            withOrderProductId: true,
                        )->defaultItems(1),
                        MoneyTotalsRow::make(withAdjustments: false),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('laravel-crm-filament::labels.fields.created'))
                    ->since()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('invoice_id')
                    ->label(__('laravel-crm-filament::labels.fields.number'))
                    // Core 2.7.0's RecordSearch: "INV-1001", "1001" and an
                    // invoice stored before the prefix changed all match.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where(fn (Builder $query) => RecordSearch::orNumber($query, $search)))
                    ->sortable(),

                Tables\Columns\TextColumn::make('reference')
                    ->label(__('laravel-crm-filament::labels.fields.reference'))
                    // Matched literally, so `%` or `_` finds itself.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where(fn (Builder $query) => RecordSearch::orLike($query, $query->qualifyColumn('reference'), $search)))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('order.order_id')
                    ->label(__('laravel-crm-filament::labels.money.order'))
                    ->formatStateUsing(fn ($state, Invoice $record) => static::parentLabel($state, $record->getRelationValue('order')))
                    ->url(fn (Invoice $record) => static::orderUrl($record))
                    ->color('primary')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('person.name')
                    ->label(__('laravel-crm-filament::labels.fields.contact'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('organization.name')
                    ->label(__('laravel-crm-filament::labels.fields.organization'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('issue_date')
                    ->label(__('laravel-crm-filament::labels.money.issue_date'))
                    ->date()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('due_date')
                    ->label(__('laravel-crm-filament::labels.money.due_date'))
                    ->date()
                    ->sortable()
                    ->toggleable(),

                CrmMoney::column('total')
                    ->label(__('laravel-crm-filament::labels.money.total'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('fully_paid_at')
                    ->label(__('laravel-crm-filament::labels.xero.fully_paid_at'))
                    ->date()
                    ->sortable()
                    ->toggleable(),

                CrmMoney::column('amount_paid')
                    ->label(__('laravel-crm-filament::labels.money.amount_paid'))
                    ->sortable()
                    ->toggleable(),

                CrmMoney::column('amount_due')
                    ->label(ucfirst(__('laravel-crm::lang.balance_due')))
                    // Worked out as core 2.7.0's show page does: an older
                    // invoice's stored amount_due can be stale or unset.
                    ->state(fn (Invoice $record): int => static::balanceDueCents($record))
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('overdue_by')
                    ->label(__('laravel-crm-filament::labels.money.overdue_by'))
                    ->state(function (Invoice $record): ?string {
                        if ($record->fully_paid_at !== null || $record->due_date === null) {
                            return null;
                        }
                        $due = Carbon::parse($record->due_date);
                        if (! $due->isPast()) {
                            return null;
                        }

                        return $due->diffForHumans(null, true) . ' ago';
                    })
                    ->color('danger')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('sent')
                    ->label(__('laravel-crm-filament::labels.fields.sent'))
                    ->boolean()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('user_owner_id')
                    ->label(__('laravel-crm-filament::labels.fields.owner'))
                    ->multiple()
                    ->relationship('ownerUser', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('labels')
                    ->label(__('laravel-crm-filament::labels.fields.labels'))
                    ->multiple()
                    ->relationship('labels', 'name')
                    ->preload(),

                Tables\Filters\SelectFilter::make('status')
                    ->label(__('laravel-crm-filament::labels.fields.status'))
                    ->options([
                        'paid' => __('laravel-crm-filament::labels.money.amount_paid'),
                        'unpaid' => __('laravel-crm-filament::labels.money.amount_due'),
                        'overdue' => __('laravel-crm-filament::labels.money.overdue_by'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if ($value === 'paid') {
                            return $query->whereNotNull('fully_paid_at');
                        }
                        if ($value === 'unpaid') {
                            return $query->whereNull('fully_paid_at');
                        }
                        if ($value === 'overdue') {
                            return $query->whereNull('fully_paid_at')
                                ->whereNotNull('due_date')
                                ->where('due_date', '<', now()->toDateString());
                        }

                        return $query;
                    }),
            ])
            ->recordActions([
                static::sendInvoiceActionFactory()
                    ->button()
                    ->label(__('laravel-crm-filament::labels.actions.send'))
                    ->color('gray'),
                static::markPaidAction()
                    ->button()
                    ->label(__('laravel-crm-filament::labels.actions.pay'))
                    ->color('gray'),
                static::invoicePortalActionFactory()
                    ->button()
                    ->hiddenLabel()
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('gray'),
                static::downloadInvoicePdfActionFactory()
                    ->button()
                    ->hiddenLabel()
                    ->icon('heroicon-m-arrow-down-tray'),
                static::pdfPreviewAction('invoice')
                    ->button()
                    ->hiddenLabel()
                    ->icon('heroicon-m-document-magnifying-glass'),
                static::portalLinkAction('laravel-crm.portal.invoices.show')
                    ->button()
                    ->hiddenLabel()
                    ->icon('heroicon-m-link'),
                Actions\ViewAction::make()
                    ->button()
                    ->hiddenLabel(),
                Actions\EditAction::make()
                    ->button()
                    ->hiddenLabel()
                    ->hidden(fn (Invoice $record): bool => (int) ($record->getAttributes()['amount_paid'] ?? 0) > 0),
                Actions\DeleteAction::make()
                    ->button()
                    ->hiddenLabel()
                    ->requiresConfirmation()
                    ->hidden(fn (Invoice $record): bool => (int) ($record->getAttributes()['amount_paid'] ?? 0) > 0),
            ])
            ->toolbarActions([
                static::primaryBulkActionGroup(),
                Actions\BulkActionGroup::make([
                    ExportsCsv::action(
                        columns: [
                            'ID' => fn ($r) => $r->invoice_id,
                            'Reference' => fn ($r) => $r->reference,
                            'Issue date' => fn ($r) => $r->issue_date,
                            'Due date' => fn ($r) => $r->due_date,
                            'Total' => fn ($r) => ($r->total ?? 0) / 100,
                            'Currency' => fn ($r) => $r->currency,
                            'Owner' => fn ($r) => optional($r->ownerUser)->name,
                        ],
                        filename: 'invoices',
                    ),
                ]),
            ]);
    }

    /**
     * Record a payment against the invoice, as core 2.7.0's InvoicePay::pay()
     * does: any amount above zero up to what is still outstanding, so an
     * invoice can be paid in parts.
     *
     * The table row and the View/Edit headers all use this one action.
     */
    public static function markPaidAction(): Action
    {
        return Action::make('markPaid')
            ->label(__('laravel-crm-filament::labels.actions.mark_paid'))
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->modalHeading(__('laravel-crm-filament::labels.actions.record_payment'))
            // Offered until the invoice is settled, not only before the first
            // payment: the rest of a part-paid invoice has to be payable too.
            ->visible(fn (?Invoice $record): bool => $record instanceof Invoice && static::canRecordPayment($record))
            ->schema([
                TextInput::make('amount')
                    ->label(__('laravel-crm-filament::labels.money.amount'))
                    ->numeric()
                    ->required()
                    ->rule('gt:0')
                    ->maxValue(fn (?Invoice $record): ?float => $record instanceof Invoice
                        ? static::outstandingCents($record) / 100
                        : null)
                    ->default(fn (?Invoice $record): ?float => $record instanceof Invoice
                        ? static::outstandingCents($record) / 100
                        : null),
                Forms\Components\DatePicker::make('paid_at')
                    ->label(__('laravel-crm-filament::labels.money.payment_date'))
                    ->required()
                    ->default(now()->toDateString()),
            ])
            ->action(function (Invoice $record, array $data, Action $action): void {
                static::recordPayment($record, $data, $action);
            });
    }

    /**
     * What is left to pay on $invoice, in cents: its total less what has been
     * paid, never below zero.
     *
     * Worked out from the total, not read from the stored `amount_due`, which
     * older invoices may never have had set (core 2.7.0 does the same).
     */
    public static function outstandingCents(Invoice $invoice): int
    {
        return max(0, (int) $invoice->getRawOriginal('total') - (int) $invoice->getRawOriginal('amount_paid'));
    }

    /**
     * The balance shown on the list and the invoice page: nothing once the
     * invoice is marked paid, otherwise what is outstanding. Core 2.7.0's
     * show page and portal use the same formula.
     */
    public static function balanceDueCents(Invoice $invoice): int
    {
        return $invoice->fully_paid_at !== null ? 0 : static::outstandingCents($invoice);
    }

    /**
     * Whether a payment can still be recorded against $invoice.
     */
    public static function canRecordPayment(Invoice $invoice): bool
    {
        return $invoice->getAttribute('fully_paid_at') === null && static::outstandingCents($invoice) > 0;
    }

    /**
     * Whether something has been paid against $invoice but not all of it.
     */
    public static function isPartPaid(Invoice $invoice): bool
    {
        return (int) $invoice->getRawOriginal('amount_paid') > 0 && $invoice->getAttribute('fully_paid_at') === null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function recordPayment(Invoice $record, array $data, ?Action $action = null): void
    {
        $amount = Money::toInteger($data['amount'] ?? null) ?? 0;
        $paidAt = $data['paid_at'] ?? now()->toDateString();

        // The row is held while the payment is added, and the amount checked
        // again against the balance the lock read: the form's maxValue saw
        // the balance from when the modal opened, so two tabs paying the last
        // 100.00 would both have passed it (core 2.7.0's InvoicePay::pay()).
        try {
            $due = DB::transaction(function () use ($record, $amount, $paidAt): int {
                $invoice = app(InvoiceService::class)->lockForEdit($record);
                $outstanding = static::outstandingCents($invoice);

                if ($amount <= 0 || $amount > $outstanding || $invoice->getAttribute('fully_paid_at') !== null) {
                    throw ValidationException::withMessages([
                        'amount' => ucfirst(__('laravel-crm::lang.payment_exceeds_amount_due', [
                            'due' => MoneyForm::display($invoice->getAttribute('fully_paid_at') !== null ? 0 : $outstanding, $invoice->getAttribute('currency')),
                        ])),
                    ]);
                }

                // In cents throughout, so a run of part payments adds up exactly.
                $paid = (int) $invoice->getRawOriginal('amount_paid') + $amount;
                $due = (int) $invoice->getRawOriginal('total') - $paid;

                // The model setters multiply by 100, so hand them units.
                $invoice->update([
                    'amount_paid' => $paid / 100,
                    'amount_due' => $due / 100,
                    // The date of the payment that cleared the balance.
                    'fully_paid_at' => $due <= 0 ? $paidAt : null,
                ]);

                // The payment-history row is a plugin extra, written in the
                // same transaction so it cannot disagree with amount_paid.
                // Skipped (rather than throwing a missing-table
                // QueryException) when the host has not migrated it.
                if (DbSchema::hasTable((new InvoicePayment)->getTable())) {
                    InvoicePayment::create([
                        'external_id' => Uuid::uuid4()->toString(),
                        'invoice_id' => $invoice->getKey(),
                        'amount' => $amount,
                        'paid_at' => $paidAt,
                        'user_created_id' => auth()->id(),
                    ]);
                }

                return $due;
            });
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title((string) collect($exception->errors())->flatten()->first())
                ->persistent()
                ->send();

            if ($action !== null) {
                $action->halt();
            }

            throw $exception;
        }

        // A part payment leaves the invoice open, so say what is still due
        // rather than that it is paid.
        Notification::make()
            ->title($due > 0
                ? ucfirst(__('laravel-crm::lang.invoice_payment_recorded', [
                    'amount' => MoneyForm::display($amount, $record->getAttribute('currency')),
                    'due' => MoneyForm::display($due, $record->getAttribute('currency')),
                ]))
                : ucfirst(__('laravel-crm::lang.invoice_paid')))
            ->success()
            ->send();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['invoice_id', 'reference'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return (string) ($record->invoice_id ?? $record->getKey());
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter(['Reference' => $record->reference]);
    }

    protected static function crmEncryptedSearchAccessor(): \Closure
    {
        return fn ($r) => trim(($r->invoice_id ?? '') . ' ' . ($r->reference ?? ''));
    }

    public static function getRelations(): array
    {
        return [
            CrmActivitiesRelationManager::class,
            CrmNotesRelationManager::class,
            CrmTasksRelationManager::class,
            CrmCallsRelationManager::class,
            CrmMeetingsRelationManager::class,
            CrmLunchesRelationManager::class,
            CrmFilesRelationManager::class,
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('laravel-crm-filament::labels.sections.details'))
                ->schema(fn (?Invoice $record) => array_merge([
                    TextEntry::make('created_at')
                        ->label(__('laravel-crm-filament::labels.fields.created'))
                        ->since(),

                    TextEntry::make('invoice_id')
                        ->label(__('laravel-crm-filament::labels.fields.number')),

                    TextEntry::make('reference')
                        ->label(__('laravel-crm-filament::labels.fields.reference')),

                    TextEntry::make('order.order_id')
                        ->label(__('laravel-crm-filament::labels.money.order'))
                        ->formatStateUsing(fn ($state, ?Invoice $record) => static::parentLabel($state, $record?->getRelationValue('order')))
                        ->url(fn (?Invoice $record) => $record instanceof Invoice ? static::orderUrl($record) : null),

                    TextEntry::make('issue_date')
                        ->label(__('laravel-crm-filament::labels.money.issue_date'))
                        ->date(),

                    TextEntry::make('due_date')
                        ->label(__('laravel-crm-filament::labels.money.due_date'))
                        ->date(),

                    CrmMoney::entry('total')
                        ->label(__('laravel-crm-filament::labels.money.total')),

                    CrmMoney::entry('amount_paid')
                        ->label(__('laravel-crm-filament::labels.money.amount_paid')),

                    CrmMoney::entry('amount_due')
                        ->label(ucfirst(__('laravel-crm::lang.balance_due')))
                        ->state(fn (?Invoice $record): ?int => $record instanceof Invoice ? static::balanceDueCents($record) : null),

                    TextEntry::make('fully_paid_at')
                        ->label(__('laravel-crm-filament::labels.xero.fully_paid_at'))
                        ->date(),

                    TextEntry::make('terms')
                        ->label(__('laravel-crm-filament::labels.fields.terms'))
                        ->columnSpanFull(),

                    TextEntry::make('labels.name')
                        ->label(__('laravel-crm-filament::labels.fields.labels'))
                        ->badge(),

                    TextEntry::make('ownerUser.name')
                        ->label(__('laravel-crm-filament::labels.fields.owner'))
                        ->placeholder(__('laravel-crm-filament::labels.misc.unallocated')),
                ], $record ? static::crmCustomFieldEntries($record, false) : [])),

            Section::make(__('laravel-crm-filament::labels.sections.contact'))
                ->schema([
                    // Through core 2.7.0's *WithTrashed relations, so a
                    // deleted contact still shows who the invoice was for,
                    // marked deleted and with no link to a page that 404s.
                    TextEntry::make('personWithTrashed.name')
                        ->label(__('laravel-crm-filament::labels.fields.contact'))
                        ->state(fn (?Invoice $record): ?string => static::trashedAwareLabel(
                            LeadDealContactSection::personLabel($record?->personWithTrashed),
                            $record?->personWithTrashed,
                        ))
                        ->url(fn (?Invoice $record): ?string => static::isLive($person = $record?->personWithTrashed)
                            ? PersonResource::getUrl('view', ['record' => $person])
                            : null),

                    TextEntry::make('organizationWithTrashed.name')
                        ->label(__('laravel-crm-filament::labels.fields.organization'))
                        ->state(fn (?Invoice $record): ?string => static::trashedAwareLabel(
                            (string) $record?->organizationWithTrashed?->getAttribute('name'),
                            $record?->organizationWithTrashed,
                        ))
                        ->url(fn (?Invoice $record): ?string => static::isLive($organization = $record?->organizationWithTrashed)
                            ? OrganizationResource::getUrl('view', ['record' => $organization])
                            : null),
                ]),

            Section::make(__('laravel-crm-filament::labels.sections.custom_fields'))
                ->schema(fn (?Invoice $record) => $record ? static::crmCustomFieldEntries($record, true) : [])
                ->hidden(function ($record): bool {
                    if (! $record instanceof Invoice) {
                        return true;
                    }

                    return ! $record->fields()
                        ->whereHas('field', fn ($q) => $q->whereNotNull('field_group_id'))
                        ->exists();
                }),

            static::xeroSyncStateSection(fn (Invoice $invoice) => $invoice->xeroInvoice),
        ])->columns(1);
    }

    /**
     * The order an invoice was made from, or null when it has none or the
     * order is soft deleted (core's `order` relation includes trashed ones).
     */
    protected static function orderUrl(Invoice $record): ?string
    {
        $order = $record->getRelationValue('order');

        return static::isLive($order) ? OrderResource::getUrl('view', ['record' => $order]) : null;
    }

    /**
     * Whether $model is there to link to: set and not soft deleted.
     *
     * @phpstan-assert-if-true !null $model
     */
    protected static function isLive(?Model $model): bool
    {
        return $model !== null && ! static::isTrashed($model);
    }

    protected static function isTrashed(Model $model): bool
    {
        return method_exists($model, 'trashed') && (bool) $model->trashed();
    }

    /**
     * A parent document's number, with core's "(deleted)" marker when the
     * parent is soft deleted.
     */
    protected static function parentLabel(mixed $state, ?Model $parent): mixed
    {
        if ($state === null || $state === '' || $parent === null) {
            return $state;
        }

        return static::trashedAwareLabel((string) $state, $parent);
    }

    /**
     * $label, followed by "(deleted)" when $model is soft deleted.
     */
    public static function trashedAwareLabel(string $label, ?Model $model): ?string
    {
        if ($label === '') {
            return null;
        }

        if ($model !== null && static::isTrashed($model)) {
            return $label . ' (' . __('laravel-crm::lang.deleted') . ')';
        }

        return $label;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'create' => CreateInvoice::route('/create'),
            'view' => ViewInvoice::route('/{record}'),
            'edit' => EditInvoice::route('/{record}/edit'),
        ];
    }

    /**
     * The Send modal, shared by the table row and the View/Edit headers
     * (Pages\Concerns\HasInvoiceSendAction), as core 2.7.0's InvoiceSend:
     * one or more recipients, a transport failure shown rather than a 500,
     * and the invoice marked sent once the mail has gone.
     */
    public static function sendInvoiceActionFactory(): Action
    {
        return Action::make('send')
            ->label(__('laravel-crm-filament::labels.actions.send'))
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->modalHeading('Send invoice')
            ->modalSubmitActionLabel('Send')
            ->schema(fn (Invoice $record): array => [
                DocumentMail::toField(fn (): ?string => $record->person?->getPrimaryEmail()?->address),
                TextInput::make('subject')
                    ->required()
                    ->default(fn () => 'Invoice ' . $record->invoice_id),
                Textarea::make('message')
                    ->rows(8)
                    ->default(fn (): string => PortalMessage::defaultMessage(PortalMessage::INVOICE)),
                Checkbox::make('cc')
                    ->label(__('laravel-crm-filament::labels.campaign.send_me_a_copy')),
            ])
            ->action(function (array $data, Invoice $record, Action $action): void {
                static::dispatchInvoiceSend($record, $data, $action);

                // The Sent badge and column read this flag, and nothing else
                // sets it on a send.
                $record->update(['sent' => 1]);

                Notification::make()
                    ->title(ucfirst(__('laravel-crm::lang.invoice_sent')))
                    ->success()
                    ->send();
            });
    }

    public static function invoicePortalActionFactory(): Action
    {
        return Action::make('previewPortal')
            ->label(__('laravel-crm-filament::labels.actions.preview_portal'))
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('primary')
            ->visible(fn (): bool => PortalUrl::exists('laravel-crm.portal.invoices.show'))
            // Signed, not bare: the portal controller 401s an unsigned request.
            ->url(fn (Invoice $record): ?string => PortalUrl::signedFor('laravel-crm.portal.invoices.show', $record))
            ->openUrlInNewTab();
    }

    public static function downloadInvoicePdfActionFactory(): Action
    {
        return Action::make('downloadPdf')
            ->label(__('laravel-crm-filament::labels.actions.download_pdf'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action(function (Invoice $record) {
                $relative = static::renderInvoicePdfToDisk($record);

                return Response::download(
                    storage_path($relative),
                    'invoice-' . strtolower((string) ($record->invoice_id ?? $record->external_id)) . '.pdf',
                );
            });
    }

    /**
     * Render the PDF and mail it. Returns only once the mail has gone: a
     * transport or MIME failure is shown and $action halted (DocumentMail).
     *
     * @param  array<string, mixed>  $data
     */
    protected static function dispatchInvoiceSend(Invoice $record, array $data, ?Action $action = null): void
    {
        $signedUrl = PortalUrl::signedFor('laravel-crm.portal.invoices.show', $record);

        $pdfPath = static::renderInvoicePdfToDisk($record);

        DocumentMail::sendOrHalt(new SendInvoice([
            'to' => DocumentMail::recipients($data['to'] ?? null),
            'subject' => $data['subject'],
            // Null link, no placeholder: base's mailable expands it either way,
            // and an unexpanded null mails an empty anchor.
            'message' => PortalMessage::resolve(PortalMessage::INVOICE, (string) $data['message'], $signedUrl),
            'cc' => ! empty($data['cc']) ? 1 : 0,
            'onlineInvoiceLink' => $signedUrl ?? '',
            'pdf' => $pdfPath,
        ]), $action, 'invoice', $record);
    }

    protected static function renderInvoicePdfToDisk(Invoice $record): string
    {
        return CrmPdf::renderToDisk($record, 'invoice');
    }

    public static function backToIndexAction(): Action
    {
        return Action::make('backToIndex')
            ->label(__('laravel-crm-filament::labels.actions.back_to_invoices'))
            ->color('gray')
            ->icon('heroicon-o-arrow-left')
            ->url(static::getUrl('index'));
    }
}
