<?php

namespace VentureDrake\LaravelCrmFilament\Concerns\Forms;

use Filament\Forms;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Fluent;
use VentureDrake\LaravelCrm\Models\Order as OrderModel;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\PipelineStage;
use VentureDrake\LaravelCrm\Services\OrganizationService;
use VentureDrake\LaravelCrm\Services\PersonService;

/**
 * Shared "Details" left-column Section used by Quote, Order, Invoice, and
 * PurchaseOrder create/edit forms. Person + Organization Selects are
 * always present; remaining scalar fields toggle via `$opts`.
 *
 * Supported $opts keys:
 *   title             bool    Render TextInput 'title' (required)
 *   description       bool    Render Textarea 'description' (5 rows)
 *   reference         bool    Render TextInput 'reference'
 *   currency          bool    Render TextInput 'currency'
 *   issueDateKey      string  Form field name for the issue date (e.g.
 *                              'issue_at' for Quote, 'issue_date' for
 *                              Invoice/PO). Null disables the field.
 *   expiryDateKey     string  Same idea for the trailing date (e.g.
 *                              'expire_at', 'due_date', 'delivery_date').
 *                              Null disables.
 *   terms             bool    Render Textarea 'terms' (5 rows)
 *   stage             bool    Render Select 'pipeline_stage_id'
 *   owner             bool    Render Select 'user_owner_id'
 *   labels            bool    Render the `labelsField()` static
 *                              (passed in via $opts['labelsField'] callback)
 *   labelsField       callable Returns the Select for labels (since
 *                              `labelsField()` is on the calling
 *                              Resource trait, not here)
 *   customFields      ?Section Optional custom fields Section to append
 *   orderLink         bool    Render Select 'order_id' first (Invoice/PO
 *                              link back to a parent Order)
 *   pdfTemplate       ?string Registry doc type ('quote', 'invoice', 'order',
 *                              'purchase-order') to render the per-record PDF
 *                              template picker for. Null omits it.
 *
 * The Section's "Details" heading is the localised string.
 */
class SalesDetailsSection
{
    public static function make(array $opts = []): Section
    {
        $opts = array_merge([
            'title' => false,
            'description' => true,
            'reference' => true,
            'currency' => true,
            'issueDateKey' => null,
            'expiryDateKey' => null,
            'terms' => true,
            'stage' => false,
            'owner' => false,
            'labels' => false,
            'labelsField' => null,
            'customFields' => null,
            'orderLink' => false,
            'pdfTemplate' => null,
        ], $opts);

        $components = [
            self::personSelect(),
            self::organizationSelect(),
        ];

        if ($opts['orderLink']) {
            array_unshift($components, self::orderSelect());
        }

        if ($opts['title']) {
            $components[] = Forms\Components\TextInput::make('title')
                ->required()
                ->maxLength(255);
        }

        if ($opts['description']) {
            $components[] = Forms\Components\Textarea::make('description')
                ->rows(5)
                ->columnSpanFull();
        }

        if ($opts['reference'] || $opts['currency']) {
            $row = [];
            if ($opts['reference']) {
                $row[] = Forms\Components\TextInput::make('reference')
                    ->label(__('laravel-crm-filament::labels.fields.reference'))
                    ->maxLength(100);
            }
            if ($opts['currency']) {
                $row[] = Forms\Components\Select::make('currency')
                    ->label(__('laravel-crm-filament::labels.fields.currency'))
                    ->options(fn () => \VentureDrake\LaravelCrm\Http\Helpers\SelectOptions\currencies())
                    ->searchable()
                    ->default(config('laravel-crm.default_currency', 'USD'));
            }
            $components[] = Grid::make(2)->schema($row);
        }

        $dateRow = [];
        if ($opts['issueDateKey']) {
            $dateRow[] = Forms\Components\DatePicker::make($opts['issueDateKey'])
                ->label(__('laravel-crm-filament::labels.money.issue_date'));
        }
        if ($opts['expiryDateKey']) {
            $label = match ($opts['expiryDateKey']) {
                'expire_at', 'expiry_date' => 'laravel-crm-filament::labels.money.expiry_date',
                'due_date' => 'laravel-crm-filament::labels.money.due_date',
                'delivery_date' => 'laravel-crm-filament::labels.money.delivery_date',
                default => 'laravel-crm-filament::labels.money.expiry_date',
            };
            $dateRow[] = Forms\Components\DatePicker::make($opts['expiryDateKey'])
                ->label(__($label));
        }
        if ($dateRow !== []) {
            $components[] = Grid::make(2)->schema($dateRow);
        }

        if ($opts['terms']) {
            $components[] = Forms\Components\Textarea::make('terms')
                ->rows(5)
                ->columnSpanFull();
        }

        if ($opts['stage']) {
            $components[] = Forms\Components\Select::make('pipeline_stage_id')
                ->label(__('laravel-crm-filament::labels.sales.pipeline_stage'))
                ->options(fn () => PipelineStage::query()->orderBy('order')->pluck('name', 'id'))
                ->searchable()
                ->preload();
        }

        if ($opts['owner']) {
            $components[] = Forms\Components\Select::make('user_owner_id')
                ->label(__('laravel-crm-filament::labels.fields.owner'))
                ->relationship('ownerUser', 'name')
                ->searchable()
                ->preload();
        }

        if ($opts['pdfTemplate'] !== null) {
            $components[] = PdfTemplateSelect::make($opts['pdfTemplate']);
        }

        if ($opts['labels'] && is_callable($opts['labelsField'])) {
            $components[] = ($opts['labelsField'])();
        }

        if ($opts['customFields'] !== null) {
            $components[] = $opts['customFields'];
        }

        return Section::make(__('laravel-crm-filament::labels.sections.details'))
            ->schema($components);
    }

    protected static function personSelect(): Forms\Components\Select
    {
        return Forms\Components\Select::make('person_id')
            ->label(__('laravel-crm-filament::labels.fields.contact'))
            ->relationship('person', 'first_name')
            ->getOptionLabelFromRecordUsing(fn (Person $r) => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')))
            ->searchable(['first_name', 'last_name', 'middle_name', 'maiden_name'])
            ->preload()
            ->nullable()
            ->createOptionForm([
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('first_name')->required()->maxLength(255),
                    Forms\Components\TextInput::make('last_name')->maxLength(255),
                ]),
                Forms\Components\TextInput::make('email')->email()->maxLength(255),
                Forms\Components\TextInput::make('phone')->tel()->maxLength(50),
            ])
            ->createOptionUsing(function (array $data): int {
                $request = new Fluent([
                    'person_name' => trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')),
                    'phone' => $data['phone'] ?? null,
                    'phone_type' => 'mobile',
                    'phone_primary' => true,
                    'email' => $data['email'] ?? null,
                    'email_type' => 'work',
                    'email_primary' => true,
                    'user_owner_id' => auth()->id(),
                ]);

                $person = app(PersonService::class)->createFromRelated($request);

                return (int) $person->getKey();
            });
    }

    protected static function organizationSelect(): Forms\Components\Select
    {
        return Forms\Components\Select::make('organization_id')
            ->label(__('laravel-crm-filament::labels.fields.organization'))
            ->relationship('organization', 'name')
            ->searchable(['name'])
            ->preload()
            ->nullable()
            ->createOptionForm([
                Forms\Components\TextInput::make('name')->required()->maxLength(255),
                Forms\Components\TextInput::make('line1')
                    ->label(__('laravel-crm-filament::labels.contact.line1'))
                    ->maxLength(255),
                Grid::make(2)->schema([
                    Forms\Components\TextInput::make('city')
                        ->label(__('laravel-crm-filament::labels.contact.city'))
                        ->maxLength(100),
                    Forms\Components\TextInput::make('country')
                        ->label(__('laravel-crm-filament::labels.contact.country'))
                        ->maxLength(100),
                ]),
            ])
            ->createOptionUsing(function (array $data): int {
                $request = new Fluent([
                    'organization_name' => $data['name'] ?? null,
                    'line1' => $data['line1'] ?? null,
                    'suburb' => $data['city'] ?? null,
                    'country' => $data['country'] ?? null,
                    'user_owner_id' => auth()->id(),
                ]);

                $org = app(OrganizationService::class)->createFromRelated($request);

                return (int) $org->getKey();
            });
    }

    protected static function orderSelect(): Forms\Components\Select
    {
        return Forms\Components\Select::make('order_id')
            ->label(__('laravel-crm-filament::labels.fields.order'))
            ->options(fn () => OrderModel::query()->orderByDesc('id')->limit(50)->get()->mapWithKeys(fn ($o) => [$o->id => $o->order_id])->all())
            ->searchable()
            ->preload();
    }
}
