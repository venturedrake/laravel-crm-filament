<?php

namespace VentureDrake\LaravelCrmFilament\Concerns\Forms;

use Filament\Forms;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Support\Collection;
use VentureDrake\LaravelCrm\Models\AddressType;
use VentureDrake\LaravelCrm\Support\AddressContent;
use VentureDrake\LaravelCrm\Support\FormOptions;

/**
 * Tabbed Billing / Shipping addresses card for the Order create/edit form.
 *
 * Filament form state for the two tabs is flat (no nested array) — the
 * keys are `billing_address` and `shipping_address`. Use the static
 * helpers `toFormData(Order $order)` / `fromFormData(array $data)` on
 * the Create + Edit pages to flatten / re-inflate against the
 * `addresses[]` array shape that `OrderService::create/update` consumes.
 *
 * Which tabs render follows core 2.6.0's `laravel-crm.orders.form.addresses`
 * (FormOptions::addressTypes()): one type renders as a plain card titled
 * for that type, and none hides the card.
 */
class OrderAddressTabs
{
    /**
     * @param  list<string>|null  $types  FormOptions::ADDRESS_TYPES keys; null shows both.
     */
    public static function make(?array $types = null): ?Section
    {
        $types ??= array_keys(FormOptions::ADDRESS_TYPES);

        if ($types === []) {
            return null;
        }

        if (count($types) === 1) {
            return Section::make(__('laravel-crm-filament::labels.sales.' . $types[0] . '_address'))
                ->schema(self::addressFields($types[0] . '_address'));
        }

        return Section::make(__('laravel-crm-filament::labels.contact.addresses'))
            ->schema([
                Tabs::make('addresses_tabs')
                    ->tabs(array_map(
                        fn (string $type) => Tab::make(__('laravel-crm-filament::labels.contact.' . $type))
                            ->schema(self::addressFields($type . '_address')),
                        $types,
                    ))
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Convert the parent record's persisted addresses into the form's
     * tabbed-state shape. The returned array has keys
     * `billing_address` + `shipping_address`, each itself an associative
     * array of the address fields.
     */
    public static function toFormData(Collection $addresses): array
    {
        $byType = [];
        foreach ($addresses as $a) {
            $byType[(int) $a->address_type_id] = $a;
        }

        $billingId = self::billingTypeId();
        $shippingId = self::shippingTypeId();

        return [
            'billing_address' => self::addressToArray($byType[$billingId] ?? null),
            'shipping_address' => self::addressToArray($byType[$shippingId] ?? null),
        ];
    }

    /**
     * Re-inflate the form's tabbed state into the `addresses[]` shape
     * that OrderService::create/update consumes.
     *
     * Only the listed types are sent. A type the form hides is left out
     * rather than sent empty, and OrderService::update() keeps the
     * addresses of every type FormOptions::hiddenAddressTypeIds() names.
     *
     * A blank address (core 2.7.0's AddressContent::isBlank(): nothing typed
     * but perhaps a country) is left out too, so it is not saved, and on
     * edit OrderService::update() deletes a saved one cleared on the form.
     *
     * @param  list<string>|null  $types  FormOptions::ADDRESS_TYPES keys; null sends both.
     */
    public static function fromFormData(array $data, ?array $types = null): array
    {
        $types ??= array_keys(FormOptions::ADDRESS_TYPES);

        $addresses = [];

        foreach ($types as $type) {
            $address = $data[$type . '_address'] ?? null;
            if (is_array($address) && self::hasAnyField($address)) {
                $addresses[] = array_merge($address, [
                    'address_type_id' => self::typeId(FormOptions::ADDRESS_TYPES[$type]),
                ]);
            }
        }

        return $addresses;
    }

    /**
     * @return array<Forms\Components\Component>
     */
    protected static function addressFields(string $prefix): array
    {
        return [
            Forms\Components\Hidden::make($prefix . '.id'),
            Grid::make(2)->schema([
                Forms\Components\TextInput::make($prefix . '.contact')
                    ->label(__('laravel-crm-filament::labels.fields.contact'))
                    ->maxLength(255),
                Forms\Components\TextInput::make($prefix . '.phone')
                    ->label(__('laravel-crm-filament::labels.contact.phone'))
                    ->tel()
                    ->maxLength(50),
            ]),
            Forms\Components\TextInput::make($prefix . '.line1')
                ->label(__('laravel-crm-filament::labels.contact.line1'))
                ->maxLength(255),
            Forms\Components\TextInput::make($prefix . '.line2')
                ->label(__('laravel-crm-filament::labels.contact.line2'))
                ->maxLength(255),
            Forms\Components\TextInput::make($prefix . '.line3')
                ->label(__('laravel-crm-filament::labels.contact.line3'))
                ->maxLength(255),
            Grid::make(2)->schema([
                Forms\Components\TextInput::make($prefix . '.city')
                    ->label(__('laravel-crm-filament::labels.contact.city'))
                    ->maxLength(100),
                Forms\Components\TextInput::make($prefix . '.state')
                    ->label(__('laravel-crm-filament::labels.contact.state'))
                    ->maxLength(100),
            ]),
            Grid::make(2)->schema([
                Forms\Components\TextInput::make($prefix . '.code')
                    ->label(__('laravel-crm-filament::labels.contact.post_code'))
                    ->maxLength(20),
                Forms\Components\TextInput::make($prefix . '.country')
                    ->label(__('laravel-crm-filament::labels.contact.country'))
                    ->maxLength(100),
            ]),
        ];
    }

    protected static function addressToArray($address): array
    {
        if (! $address) {
            return [];
        }

        return [
            'id' => $address->id,
            'contact' => $address->contact,
            'phone' => $address->phone,
            'line1' => $address->line1,
            'line2' => $address->line2,
            'line3' => $address->line3,
            'city' => $address->city,
            'state' => $address->state,
            'code' => $address->code,
            'country' => $address->country,
        ];
    }

    /**
     * Whether someone typed anything into the address. A country on its own
     * does not count: core 2.7.0 stopped saving country-only addresses
     * (#115), as the country is often all that is left of a cleared one.
     */
    protected static function hasAnyField(array $address): bool
    {
        return ! AddressContent::isBlank($address);
    }

    protected static function billingTypeId(): ?int
    {
        return self::typeId('Billing');
    }

    protected static function shippingTypeId(): ?int
    {
        return self::typeId('Shipping');
    }

    protected static function typeId(string $name): ?int
    {
        return AddressType::query()->where('name', $name)->value('id');
    }
}
