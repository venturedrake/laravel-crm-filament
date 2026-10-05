<?php

namespace VentureDrake\LaravelCrmFilament\Concerns\Forms;

use Filament\Forms;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Fluent;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Services\OrganizationService;
use VentureDrake\LaravelCrm\Services\PersonService;
use VentureDrake\LaravelCrmFilament\Support\EncryptedContactSearch;

/**
 * Shared "Contact" and "Organization" left-column Sections used by Lead +
 * Deal create/edit forms to mirror the core CRM /crm/leads/create layout.
 *
 * Each Section exposes a single searchable Select (person_id /
 * organization_id) with an inline `createOptionForm` modal that mirrors
 * the field set of core's `PersonService::createFromRelated` /
 * `OrganizationService::createFromRelated`. The modal persists a fresh
 * Person / Organization through those services and returns its id so the
 * parent Select binds cleanly.
 */
class LeadDealContactSection
{
    /**
     * Compose a person's display name (first + last), matching the
     * convention used by the contactColumn() Select's
     * getOptionLabelFromRecordUsing closure.
     */
    public static function personLabel(?Person $person): string
    {
        if ($person === null) {
            return '';
        }

        return trim(($person->first_name ?? '') . ' ' . ($person->last_name ?? ''));
    }

    public static function contactColumn(): Section
    {
        return Section::make(__('laravel-crm-filament::labels.fields.contact'))
            ->schema([
                Forms\Components\Select::make('person_id')
                    ->label(__('laravel-crm-filament::labels.fields.contact'))
                    ->relationship('person', 'first_name')
                    ->getOptionLabelFromRecordUsing(fn (Person $r) => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')))
                    ->searchable(['first_name', 'last_name', 'middle_name', 'maiden_name'])
                    ->preload()
                    ->tap(fn (Forms\Components\Select $select) => EncryptedContactSearch::personSelect($select))
                    ->nullable()
                    ->createOptionForm([
                        Grid::make(2)->schema([
                            Forms\Components\TextInput::make('first_name')
                                ->required()
                                ->maxLength(255),
                            Forms\Components\TextInput::make('last_name')
                                ->maxLength(255),
                        ]),
                        Grid::make(2)->schema([
                            Forms\Components\TextInput::make('phone')
                                ->tel()
                                ->maxLength(50),
                            Forms\Components\Select::make('phone_type')
                                ->options([
                                    'work' => 'Work',
                                    'home' => 'Home',
                                    'mobile' => 'Mobile',
                                    'fax' => 'Fax',
                                    'other' => 'Other',
                                ])
                                ->default('mobile'),
                        ]),
                        Grid::make(2)->schema([
                            Forms\Components\TextInput::make('email')
                                ->email()
                                ->maxLength(255),
                            Forms\Components\Select::make('email_type')
                                ->options([
                                    'work' => 'Work',
                                    'home' => 'Home',
                                    'other' => 'Other',
                                ])
                                ->default('work'),
                        ]),
                    ])
                    ->createOptionUsing(function (array $data): int {
                        $request = new Fluent([
                            'person_name' => trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')),
                            'phone' => $data['phone'] ?? null,
                            'phone_type' => $data['phone_type'] ?? null,
                            'phone_primary' => true,
                            'email' => $data['email'] ?? null,
                            'email_type' => $data['email_type'] ?? null,
                            'email_primary' => true,
                            'user_owner_id' => auth()->id(),
                        ]);

                        $person = app(PersonService::class)->createFromRelated($request);

                        return (int) $person->getKey();
                    }),
            ]);
    }

    public static function organizationColumn(): Section
    {
        return Section::make(__('laravel-crm-filament::labels.fields.organization'))
            ->schema([
                Forms\Components\Select::make('organization_id')
                    ->label(__('laravel-crm-filament::labels.fields.organization'))
                    ->relationship('organization', 'name')
                    ->searchable(['name'])
                    ->preload()
                    ->tap(fn (Forms\Components\Select $select) => EncryptedContactSearch::organizationSelect($select))
                    ->nullable()
                    ->createOptionForm([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('line1')
                            ->label(__('laravel-crm-filament::labels.contact.line1'))
                            ->maxLength(255),
                        Forms\Components\TextInput::make('line2')
                            ->label(__('laravel-crm-filament::labels.contact.line2'))
                            ->maxLength(255),
                        Forms\Components\TextInput::make('line3')
                            ->label(__('laravel-crm-filament::labels.contact.line3'))
                            ->maxLength(255),
                        Grid::make(2)->schema([
                            Forms\Components\TextInput::make('city')
                                ->label(__('laravel-crm-filament::labels.contact.city'))
                                ->maxLength(100),
                            Forms\Components\TextInput::make('state')
                                ->label(__('laravel-crm-filament::labels.contact.state'))
                                ->maxLength(100),
                        ]),
                        Grid::make(2)->schema([
                            Forms\Components\TextInput::make('code')
                                ->label(__('laravel-crm-filament::labels.contact.post_code'))
                                ->maxLength(20),
                            Forms\Components\TextInput::make('country')
                                ->label(__('laravel-crm-filament::labels.contact.country'))
                                ->maxLength(100),
                        ]),
                    ])
                    ->createOptionUsing(function (array $data): int {
                        $request = new Fluent([
                            'organization_name' => $data['name'] ?? null,
                            'line1' => $data['line1'] ?? null,
                            'line2' => $data['line2'] ?? null,
                            'line3' => $data['line3'] ?? null,
                            'suburb' => $data['city'] ?? null,
                            'state' => $data['state'] ?? null,
                            'code' => $data['code'] ?? null,
                            'country' => $data['country'] ?? null,
                            'user_owner_id' => auth()->id(),
                        ]);

                        $org = app(OrganizationService::class)->createFromRelated($request);

                        return (int) $org->getKey();
                    }),
            ]);
    }
}
