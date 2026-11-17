<?php

namespace VentureDrake\LaravelCrmFilament\Concerns;

use Filament\Forms;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Http\Rules\ContactEmail;
use VentureDrake\LaravelCrm\Http\Rules\PhoneNumber;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;

/**
 * Reusable Filament schema sections for CRM contact fields
 * (phones, emails, addresses). Used on Person and Organization forms.
 *
 * The repeater item shape matches exactly what PersonService / OrganizationService
 * update helpers consume:
 *   phones     -> [{id, number, type, primary}]
 *   emails     -> [{id, address, type, primary}]
 *   addresses  -> [{id, line1, line2, line3, city, state, code, country, primary}]
 *
 * The phone, email and type field builders are shared with the person modal
 * on the lead, deal and document forms, so every contact entry point checks
 * the same way core 2.7.0 does.
 */
class ContactFieldsSchema
{
    /** @var array<string, string> */
    public const PHONE_TYPES = [
        'work' => 'Work',
        'home' => 'Home',
        'mobile' => 'Mobile',
        'fax' => 'Fax',
        'other' => 'Other',
    ];

    /** @var array<string, string> */
    public const EMAIL_TYPES = [
        'work' => 'Work',
        'home' => 'Home',
        'other' => 'Other',
    ];

    /**
     * A phone number input checked with core 2.7.0's lenient PhoneNumber rule
     * (digits, spaces, + - ( ) . and an extension) rather than Filament's
     * tel() regex, which refuses an extension. Numbers already saved on the
     * record pass as they are, so one that predates the rule or came from an
     * import doesn't block an unrelated edit. A create-contact modal passes
     * `$forRecord: false`, as nothing it enters is saved yet.
     */
    public static function phoneInput(string $name = 'number', bool $forRecord = true): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make($name)
            ->type('tel')
            ->maxLength(50)
            ->rule(fn (?Model $record) => new PhoneNumber($forRecord ? static::savedPhones($record) : []));
    }

    /**
     * An email input checked with core 2.7.0's ContactEmail rule (`email:rfc`,
     * no DNS lookup). Not type="email": the browser would refuse a saved
     * legacy address the rule lets through.
     */
    public static function emailInput(string $name = 'address', bool $forRecord = true): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make($name)
            ->inputMode('email')
            ->maxLength(255)
            ->rule(fn (?Model $record) => new ContactEmail($forRecord ? static::savedEmails($record) : []));
    }

    /**
     * The phone type, defaulting to mobile as core 2.7.0's forms do.
     */
    public static function phoneTypeSelect(string $name = 'type'): Forms\Components\Select
    {
        return Forms\Components\Select::make($name)
            ->label(__('laravel-crm-filament::labels.fields.type'))
            ->options(self::PHONE_TYPES)
            ->default('mobile')
            ->selectablePlaceholder(false);
    }

    /**
     * The email type, defaulting to work as core 2.7.0's forms do.
     */
    public static function emailTypeSelect(string $name = 'type'): Forms\Components\Select
    {
        return Forms\Components\Select::make($name)
            ->label(__('laravel-crm-filament::labels.fields.type'))
            ->options(self::EMAIL_TYPES)
            ->default('work')
            ->selectablePlaceholder(false);
    }

    /**
     * The numbers already saved on the record; none on create. Any record
     * with phones, as the user form shares the phones repeater with people
     * and organizations.
     *
     * @return array<int, string>
     */
    public static function savedPhones(?Model $record): array
    {
        if (! $record?->exists || ! method_exists($record, 'phones')) {
            return [];
        }

        // Through the models, not a query pluck, so an encrypted number is
        // compared decrypted.
        return $record->phones()->get()->pluck('number')->filter()->values()->all();
    }

    /**
     * @return array<int, string>
     */
    public static function savedEmails(?Model $record): array
    {
        if (! $record instanceof Person && ! $record instanceof Organization) {
            return [];
        }

        return $record->emails()->get()->pluck('address')->filter()->values()->all();
    }

    /** @return array<Forms\Components\Repeater> */
    public static function phonesRepeater(string $name = 'phones'): Forms\Components\Repeater
    {
        return Forms\Components\Repeater::make($name)
            ->label(__('laravel-crm-filament::labels.contact.phone_numbers'))
            ->schema([
                Forms\Components\Hidden::make('id'),
                static::phoneInput('number')
                    ->label(__('laravel-crm-filament::labels.contact.number'))
                    ->required(),
                static::phoneTypeSelect()
                    ->required(),
                Forms\Components\Toggle::make('primary')
                    ->label(__('laravel-crm-filament::labels.fields.primary'))
                    ->default(false),
            ])
            ->columns(4)
            ->addActionLabel('Add phone')
            ->defaultItems(0)
            ->reorderable(false)
            ->columnSpanFull();
    }

    public static function emailsRepeater(string $name = 'emails'): Forms\Components\Repeater
    {
        return Forms\Components\Repeater::make($name)
            ->label(__('laravel-crm-filament::labels.contact.email_addresses'))
            ->schema([
                Forms\Components\Hidden::make('id'),
                static::emailInput('address')
                    ->label(__('laravel-crm-filament::labels.contact.email'))
                    ->required(),
                static::emailTypeSelect()
                    ->required(),
                Forms\Components\Toggle::make('primary')
                    ->label(__('laravel-crm-filament::labels.fields.primary'))
                    ->default(false),
            ])
            ->columns(4)
            ->addActionLabel('Add email')
            ->defaultItems(0)
            ->reorderable(false)
            ->columnSpanFull();
    }

    public static function addressesRepeater(string $name = 'addresses'): Forms\Components\Repeater
    {
        return Forms\Components\Repeater::make($name)
            ->label(__('laravel-crm-filament::labels.contact.addresses'))
            ->schema([
                Forms\Components\Hidden::make('id'),
                Forms\Components\TextInput::make('line1')->label(__('laravel-crm-filament::labels.contact.line1'))->maxLength(255),
                Forms\Components\TextInput::make('line2')->label(__('laravel-crm-filament::labels.contact.line2'))->maxLength(255),
                Forms\Components\TextInput::make('line3')->label(__('laravel-crm-filament::labels.contact.line3'))->maxLength(255),
                Forms\Components\TextInput::make('city')->label(__('laravel-crm-filament::labels.contact.city'))->maxLength(100),
                Forms\Components\TextInput::make('state')->label(__('laravel-crm-filament::labels.contact.state'))->maxLength(100),
                Forms\Components\TextInput::make('code')->label(__('laravel-crm-filament::labels.contact.post_code'))->maxLength(20),
                Forms\Components\TextInput::make('country')->label(__('laravel-crm-filament::labels.contact.country'))->maxLength(100),
                Forms\Components\Toggle::make('primary')->label(__('laravel-crm-filament::labels.fields.primary'))->default(false),
            ])
            ->columns(2)
            ->addActionLabel('Add address')
            ->defaultItems(0)
            ->reorderable(false)
            ->columnSpanFull();
    }
}
