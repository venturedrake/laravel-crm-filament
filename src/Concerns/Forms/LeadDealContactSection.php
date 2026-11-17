<?php

namespace VentureDrake\LaravelCrmFilament\Concerns\Forms;

use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Fluent;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Services\OrganizationService;
use VentureDrake\LaravelCrm\Services\PersonService;
use VentureDrake\LaravelCrmFilament\Concerns\ContactFieldsSchema;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\OrganizationResource;
use VentureDrake\LaravelCrmFilament\Resources\People\PersonResource;
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

    /**
     * The contact on a lead or deal show page. Read through
     * personWithTrashed(), as core 2.7.0's show pages do, so a deleted
     * person reads "Name (deleted)" with no link instead of going blank.
     */
    public static function personEntry(string $name = 'person.name'): TextEntry
    {
        return TextEntry::make($name)
            ->label(__('laravel-crm-filament::labels.fields.contact'))
            ->state(function (?Model $record): ?string {
                $person = static::trashedContact($record, 'personWithTrashed');

                return $person instanceof Person
                    ? static::trashedAwareName($person, static::personLabel($person))
                    : null;
            })
            ->url(function (?Model $record): ?string {
                $person = static::trashedContact($record, 'personWithTrashed');

                return $person !== null && ! $person->trashed()
                    ? PersonResource::getUrl('view', ['record' => $person])
                    : null;
            });
    }

    public static function organizationEntry(string $name = 'organization.name'): TextEntry
    {
        return TextEntry::make($name)
            ->label(__('laravel-crm-filament::labels.fields.organization'))
            ->state(function (?Model $record): ?string {
                $organization = static::trashedContact($record, 'organizationWithTrashed');

                return $organization instanceof Organization
                    ? static::trashedAwareName($organization, (string) $organization->getAttribute('name'))
                    : null;
            })
            ->url(function (?Model $record): ?string {
                $organization = static::trashedContact($record, 'organizationWithTrashed');

                return $organization !== null && ! $organization->trashed()
                    ? OrganizationResource::getUrl('view', ['record' => $organization])
                    : null;
            });
    }

    /**
     * The contact off core's HasTrashedContacts relation, deleted or not.
     */
    protected static function trashedContact(?Model $record, string $relation): Person | Organization | null
    {
        $contact = $record?->getRelationValue($relation);

        return $contact instanceof Person || $contact instanceof Organization ? $contact : null;
    }

    protected static function trashedAwareName(Person | Organization $contact, string $label): string
    {
        return $contact->trashed()
            ? trim($label . ' (' . __('laravel-crm::lang.deleted') . ')')
            : $label;
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
                            ContactFieldsSchema::phoneInput('phone', forRecord: false),
                            ContactFieldsSchema::phoneTypeSelect('phone_type')
                                ->requiredWith('phone'),
                        ]),
                        Grid::make(2)->schema([
                            ContactFieldsSchema::emailInput('email', forRecord: false),
                            ContactFieldsSchema::emailTypeSelect('email_type')
                                ->requiredWith('email'),
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
