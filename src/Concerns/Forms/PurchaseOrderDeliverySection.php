<?php

namespace VentureDrake\LaravelCrmFilament\Concerns\Forms;

use Filament\Forms;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use VentureDrake\LaravelCrm\Models\Address;
use VentureDrake\LaravelCrm\Models\PurchaseOrder;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrm\Support\AddressContent;

/**
 * "Delivery Details" left-column Section for the PurchaseOrder form.
 *
 * Renders:
 *   delivery_type           Select live (deliver / pickup)
 *   delivery_address        Select (visible when delivery_type='deliver')
 *   delivery_instructions   Textarea (visible when delivery_type='deliver')
 *
 * As core 2.7.0's HasPurchaseOrderCommon does it: a PO is delivered to one of
 * the team's own addresses (the company addresses under Settings), and only
 * one that can be delivered to - a street or a city, not just a country.
 * PurchaseOrderService drops any other id, so the form offers only these and
 * refuses anything else with an `in` rule. On edit the PO's own copy of the
 * address it was saved with is offered first, as the form starts on it.
 */
class PurchaseOrderDeliverySection
{
    public const DELIVER = 'deliver';

    public const PICKUP = 'pickup';

    public static function make(): Section
    {
        return Section::make(__('laravel-crm-filament::labels.sections.delivery_details'))
            ->schema([
                Grid::make(1)->schema([
                    Forms\Components\Select::make('delivery_type')
                        ->label(__('laravel-crm-filament::labels.sales.delivery_type'))
                        ->options(self::typeOptions())
                        ->live()
                        // Core 2.7.0 starts a new PO on pickup when the team
                        // has nowhere to deliver to, rather than on a deliver
                        // that cannot be saved.
                        ->default(fn (): string => self::teamDeliveryAddresses() === [] ? self::PICKUP : self::DELIVER),

                    Forms\Components\Select::make('delivery_address')
                        ->label(__('laravel-crm-filament::labels.sales.delivery_address'))
                        ->options(fn (?PurchaseOrder $record): array => self::deliveryAddressOptions($record))
                        ->searchable()
                        ->preload()
                        ->required()
                        // Checked against the addresses read fresh on submit,
                        // so one removed since the form opened is refused too.
                        ->in(fn (?PurchaseOrder $record): array => array_keys(self::deliveryAddressOptions($record)))
                        ->validationMessages([
                            'required' => fn (?PurchaseOrder $record): string => self::addressMessage($record),
                            'in' => fn (?PurchaseOrder $record): string => self::addressMessage($record),
                        ])
                        ->helperText(fn (?PurchaseOrder $record): ?string => self::deliveryAddressOptions($record) === []
                            ? ucfirst(__('laravel-crm::lang.delivery_address_unavailable'))
                            : null)
                        ->visible(fn (Get $get): bool => $get('delivery_type') === self::DELIVER),

                    Forms\Components\Textarea::make('delivery_instructions')
                        ->label(__('laravel-crm-filament::labels.sales.delivery_instructions'))
                        ->rows(3)
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => $get('delivery_type') === self::DELIVER),
                ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            self::DELIVER => __('laravel-crm-filament::labels.sales.deliver'),
            // A panel key rather than core's `pickup`, which has no es/fr.
            self::PICKUP => __('laravel-crm-filament::labels.sales.pickup'),
        ];
    }

    /**
     * The label for a stored delivery type. POs the panel saved before core
     * 2.7.0 say `collect`, which is pickup.
     */
    public static function typeLabel(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }

        return self::typeOptions()[self::normalizeType($type)] ?? $type;
    }

    /**
     * The form value for a stored delivery type: `collect` (the panel's name
     * for it before core 2.7.0) is `pickup`, and no type at all is deliver,
     * as core's edit form starts it.
     */
    public static function normalizeType(?string $type): string
    {
        return match ($type) {
            null, '' => self::DELIVER,
            'collect' => self::PICKUP,
            default => $type,
        };
    }

    /**
     * The id the edit form starts on: the PO's own copy of its address, when
     * that copy can be delivered to. One that cannot is not offered, so it is
     * not preselected either - the PO has to be given a real address.
     */
    public static function currentAddressId(PurchaseOrder $purchaseOrder): ?int
    {
        $current = self::currentAddress($purchaseOrder);

        return $current && AddressContent::isDeliverable($current) ? (int) $current->getKey() : null;
    }

    /**
     * The addresses offered, keyed by id: the PO's current copy (on edit),
     * then the team's deliverable addresses.
     *
     * @return array<int, string>
     */
    public static function deliveryAddressOptions(?PurchaseOrder $record = null): array
    {
        $options = [];

        if ($record && ($currentId = self::currentAddressId($record)) !== null) {
            // The accessor builds a label from the parts when there is none,
            // which for a sparse address is mostly separators.
            $label = trim((string) self::currentAddress($record)?->getAttribute('address'), " ,\t\n");

            $options[$currentId] = ucfirst(__('laravel-crm::lang.current_delivery_address'))
                . ($label !== '' ? ': ' . $label : '');
        }

        foreach (self::teamDeliveryAddresses() as $address) {
            $options[(int) $address->getKey()] = trim((string) $address->getAttribute('address'), " ,\t\n");
        }

        return $options;
    }

    /**
     * The team's addresses a PO can be delivered to, as core reads them.
     * Filtered in PHP rather than SQL, as the fields may be encrypted.
     *
     * @return list<Address>
     */
    public static function teamDeliveryAddresses(): array
    {
        /** @var Setting|null $team */
        $team = app('laravel-crm.settings')->first('team');
        $addresses = $team ? $team->getRelationValue('addresses') : [];

        return collect($addresses)
            ->filter(fn (Address $address): bool => AddressContent::isDeliverable($address))
            ->values()
            ->all();
    }

    /**
     * The PO's own copy of the address it is delivered to.
     */
    protected static function currentAddress(PurchaseOrder $purchaseOrder): ?Address
    {
        /** @var Address|null $address */
        $address = $purchaseOrder->getRelationValue('address');

        return $address;
    }

    protected static function addressMessage(?PurchaseOrder $record): string
    {
        return ucfirst(__(self::deliveryAddressOptions($record) === []
            ? 'laravel-crm::lang.delivery_address_unavailable'
            : 'laravel-crm::lang.delivery_address_required'));
    }
}
