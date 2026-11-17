<?php

use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\HasSchemas;
use Filament\Schemas\Schema;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LeadDealContactSection;
use VentureDrake\LaravelCrmFilament\Resources\Deals\DealResource;
use VentureDrake\LaravelCrmFilament\Resources\Deals\Pages\ViewDeal;
use VentureDrake\LaravelCrmFilament\Resources\Orders\OrderResource;
use VentureDrake\LaravelCrmFilament\Resources\Orders\Pages\ViewOrder;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\ViewQuote;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\QuoteResource;

dataset('dqoResourceModelPage', [
    'Deal' => [DealResource::class, Deal::class, ViewDeal::class],
    'Quote' => [QuoteResource::class, Quote::class, ViewQuote::class],
    'Order' => [OrderResource::class, Order::class, ViewOrder::class],
]);

function dqoInfolistSections(string $resource, string $model): array
{
    $record = new $model;
    $schema = Schema::make(new class
    {
        use HasSchemas;

        public function getKey(): string
        {
            return 'test';
        }
    });
    $schema->record($record);
    $resource::infolist($schema);

    return array_values(array_filter(
        $schema->getComponents(withHidden: true),
        fn ($c) => $c instanceof Section,
    ));
}

it('declares infolist() locally on the Resource', function (string $resource): void {
    $declaringClass = (new ReflectionMethod($resource, 'infolist'))->getDeclaringClass()->getName();
    expect($declaringClass)->toBe($resource);
})->with('dqoResourceModelPage');

it('infolist source contains Details / Contact / Custom fields section headings', function (string $resource): void {
    $src = file_get_contents((new ReflectionClass($resource))->getFileName());

    expect($src)->toContain("Section::make(__('laravel-crm-filament::labels.sections.details'))");
    expect($src)->toContain("Section::make(__('laravel-crm-filament::labels.sections.contact'))");
    expect($src)->toContain("Section::make(__('laravel-crm-filament::labels.sections.custom_fields'))");
})->with('dqoResourceModelPage');

it('infolist source uses crmCustomFieldEntries() helper for the custom-fields section', function (string $resource): void {
    $src = file_get_contents((new ReflectionClass($resource))->getFileName());

    // Both the flat (false) call (merged into Details) and the grouped (true) call (in Custom fields).
    expect($src)->toContain('static::crmCustomFieldEntries($record, false)');
    expect($src)->toContain('static::crmCustomFieldEntries($record, true)');
})->with('dqoResourceModelPage');

it('infolist source uses PersonResource::getUrl and OrganizationResource::getUrl for contact links', function (string $resource): void {
    $src = file_get_contents((new ReflectionClass($resource))->getFileName());

    // The deal's contact entries come from LeadDealContactSection, which
    // reads the trashed-aware relations (core 2.7.0).
    if ($resource === DealResource::class) {
        expect($src)->toContain('LeadDealContactSection::personEntry()');
        expect($src)->toContain('LeadDealContactSection::organizationEntry()');

        $src = file_get_contents((new ReflectionClass(LeadDealContactSection::class))->getFileName());
    }

    expect($src)->toContain("PersonResource::getUrl('view'");
    expect($src)->toContain("OrganizationResource::getUrl('view'");
})->with('dqoResourceModelPage');

it('OrderResource infolist still surfaces the Xero sync state section', function (): void {
    $src = file_get_contents((new ReflectionClass(OrderResource::class))->getFileName());
    expect($src)->toContain('xeroSyncStateSection(');
    expect($src)->toContain('invoices()');
    expect($src)->toContain('xeroInvoice');
});
