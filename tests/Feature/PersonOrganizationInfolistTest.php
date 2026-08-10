<?php

use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFieldEntries;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\OrganizationResource;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\Pages\ViewOrganization;
use VentureDrake\LaravelCrmFilament\Resources\People\Pages\ViewPerson;
use VentureDrake\LaravelCrmFilament\Resources\People\PersonResource;

dataset('poResourceModelPage', [
    'Person' => [PersonResource::class, Person::class, ViewPerson::class],
    'Organization' => [OrganizationResource::class, Organization::class, ViewOrganization::class],
]);

function poInfolistSections(string $resource, string $model, string $page): array
{
    $instance = (new ReflectionClass($page))->newInstanceWithoutConstructor();
    $instance->record = new $model;
    $schema = Schema::make($instance);
    $schema->record($instance->record);
    $resource::infolist($schema);

    return array_values(array_filter(
        $schema->getComponents(withHidden: true),
        fn ($c) => $c instanceof Section,
    ));
}

it('declares infolist() locally on the Resource', function (string $resource): void {
    $declaringClass = (new ReflectionMethod($resource, 'infolist'))->getDeclaringClass()->getName();
    expect($declaringClass)->toBe($resource);
})->with('poResourceModelPage');

it('Organization infolist source contains Details + Custom fields section headings only', function (): void {
    $src = file_get_contents((new ReflectionClass(OrganizationResource::class))->getFileName());

    expect($src)->toContain("Section::make(__('laravel-crm-filament::labels.sections.details'))");
    expect($src)->toContain("Section::make(__('laravel-crm-filament::labels.sections.custom_fields'))");
    expect($src)->not->toContain("Section::make(__('laravel-crm-filament::labels.sections.contact'))");
});

it('Person infolist source contains Identity + Custom fields section headings only', function (): void {
    $src = file_get_contents((new ReflectionClass(PersonResource::class))->getFileName());

    expect($src)->toContain("Section::make(__('laravel-crm-filament::labels.sections.identity'))");
    expect($src)->toContain("Section::make(__('laravel-crm-filament::labels.sections.custom_fields'))");
    expect($src)->not->toContain("Section::make(__('laravel-crm-filament::labels.sections.contact'))");
});

it('Organization infolist exposes two top-level Sections in Details / Custom fields order', function (): void {
    $sections = poInfolistSections(OrganizationResource::class, Organization::class, ViewOrganization::class);

    expect($sections)->toHaveCount(2);
    expect($sections[0]->getHeading())->toBe(__('laravel-crm-filament::labels.sections.details'));
    expect($sections[1]->getHeading())->toBe(__('laravel-crm-filament::labels.sections.custom_fields'));
});

it('Person infolist exposes two top-level Sections in Identity / Custom fields order', function (): void {
    $sections = poInfolistSections(PersonResource::class, Person::class, ViewPerson::class);

    expect($sections)->toHaveCount(2);
    expect($sections[0]->getHeading())->toBe(__('laravel-crm-filament::labels.sections.identity'));
    expect($sections[1]->getHeading())->toBe(__('laravel-crm-filament::labels.sections.custom_fields'));
});

it('Person infolist Identity carries first/last/middle name + per-row phones / emails / addresses + labels + owner', function (): void {
    $src = file_get_contents((new ReflectionClass(PersonResource::class))->getFileName());

    expect($src)->toContain("TextEntry::make('first_name')");
    expect($src)->toContain("TextEntry::make('last_name')");
    expect($src)->toContain("TextEntry::make('middle_name')");
    expect($src)->toContain("TextEntry::make('gender')");
    expect($src)->toContain("TextEntry::make('birthday')");
    expect($src)->toContain("TextEntry::make('description')");
    // Per-row loops over phones / emails / addresses produce dynamic
    // entry names (phone_0, email_0, address_0, ...) inside the
    // personDetailEntries() helper.
    expect($src)->toContain('foreach ($record->phones as $i => $phone)');
    expect($src)->toContain('foreach ($record->emails as $i => $email)');
    expect($src)->toContain('foreach ($record->addresses as $i => $address)');
    expect($src)->toContain("TextEntry::make('labels.name')");
    expect($src)->toContain("TextEntry::make('ownerUser.name')");
});

it('Organization infolist Details carries the core CRM detail fields + per-row phones/emails/addresses + labels + integrations + owner', function (): void {
    $src = file_get_contents((new ReflectionClass(OrganizationResource::class))->getFileName());

    // Scalar identity-style fields ordered to match the laravel-crm
    // Details card.
    expect($src)->toContain("TextEntry::make('organizationType.name')");
    expect($src)->toContain("TextEntry::make('vat_number')");
    expect($src)->toContain("TextEntry::make('industry.name')");
    expect($src)->toContain("TextEntry::make('timezone.name')");
    expect($src)->toContain("TextEntry::make('number_of_employees')");
    expect($src)->toContain("CrmMoney::entry('annual_revenue')");
    expect($src)->toContain("TextEntry::make('linkedin')");
    expect($src)->toContain("TextEntry::make('description')");

    // Per-row loops + tail entries (labels, integrations, owner).
    expect($src)->toContain('foreach ($record->phones as $i => $phone)');
    expect($src)->toContain('foreach ($record->emails as $i => $email)');
    expect($src)->toContain('foreach ($record->addresses as $i => $address)');
    expect($src)->toContain("TextEntry::make('labels.name')");
    expect($src)->toContain("TextEntry::make('integrations')");
    expect($src)->toContain("TextEntry::make('ownerUser.name')");
});

it('Person infolist renders per-row address TextEntries instead of one merged addresses field', function (): void {
    $src = file_get_contents((new ReflectionClass(PersonResource::class))->getFileName());

    // Per-row entries land in personDetailEntries() as
    // TextEntry::make('address_0'), TextEntry::make('address_1'), ...
    expect($src)->toContain("TextEntry::make('address_' . \$i)");
    expect($src)->toContain('static::formatAddressLine($address)');
});

it('both infolists merge ungrouped custom field entries into Identity', function (string $resource): void {
    $src = file_get_contents((new ReflectionClass($resource))->getFileName());

    expect($src)->toContain('static::crmCustomFieldEntries($record, false)');
    expect($src)->toContain('static::crmCustomFieldEntries($record, true)');
})->with('poResourceModelPage');

it('Custom fields section is hidden when the record has no grouped FieldValues', function (string $resource, string $model, string $page): void {
    $sections = poInfolistSections($resource, $model, $page);
    // Custom fields is the LAST section regardless of how many sections precede it.
    $custom = $sections[array_key_last($sections)];

    $ref = new ReflectionProperty($custom, 'isHidden');
    $ref->setAccessible(true);
    $closure = $ref->getValue($custom);

    expect($closure)->toBeInstanceOf(Closure::class);
    expect($closure(new $model))->toBeTrue();
    expect($closure(null))->toBeTrue();
})->with('poResourceModelPage');

it('uses the shared HasCrmCustomFieldEntries trait on both Resources', function (string $resource): void {
    expect(class_uses_recursive($resource))
        ->toContain(HasCrmCustomFieldEntries::class);
})->with('poResourceModelPage');
