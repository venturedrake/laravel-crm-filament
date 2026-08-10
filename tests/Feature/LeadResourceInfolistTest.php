<?php

use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Field;
use VentureDrake\LaravelCrm\Models\FieldGroup;
use VentureDrake\LaravelCrm\Models\FieldValue;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrmFilament\Concerns\Forms\LeadDealContactSection;
use VentureDrake\LaravelCrmFilament\Resources\Leads\LeadResource;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\ViewLead;

it('declares an infolist override on LeadResource (not inherited)', function () {
    $method = new ReflectionMethod(LeadResource::class, 'infolist');
    expect($method->getDeclaringClass()->getName())->toBe(LeadResource::class);
    expect($method->isStatic())->toBeTrue();
    expect($method->isPublic())->toBeTrue();
});

it('LeadResource source contains the three top-level Section keys in order', function () {
    $src = file_get_contents(
        (new ReflectionClass(LeadResource::class))->getFileName(),
    );

    $detailsPos = strpos($src, "Section::make(__('laravel-crm-filament::labels.sections.details'))");
    $qualPos = strpos($src, "Section::make(__('laravel-crm-filament::labels.sections.lead_qualification'))");
    $contactPos = strpos($src, "Section::make(__('laravel-crm-filament::labels.sections.contact'))");

    expect($detailsPos)->not->toBeFalse();
    expect($qualPos)->not->toBeFalse();
    expect($contactPos)->not->toBeFalse();
    expect($detailsPos)->toBeLessThan($qualPos);
    expect($qualPos)->toBeLessThan($contactPos);
});

it('Details section wires the AC-required TextEntries with money/badge/color/placeholder', function () {
    $src = file_get_contents(
        (new ReflectionClass(LeadResource::class))->getFileName(),
    );

    expect($src)->toContain("TextEntry::make('created_at')");
    expect($src)->toContain('->since()');

    expect($src)->toContain("TextEntry::make('lead_id')");
    expect($src)->toContain("'laravel-crm-filament::labels.fields.number'");

    expect($src)->toContain("CrmMoney::entry('amount')");

    expect($src)->toContain("TextEntry::make('description')");
    expect($src)->toContain('->columnSpanFull()');

    expect($src)->toContain("TextEntry::make('leadSource.name')");

    expect($src)->toContain("TextEntry::make('labels.name')");
    expect($src)->toContain('->badge()');
    expect($src)->toContain("\$record?->labels?->firstWhere('name', \$state)");
    expect($src)->toContain('->hex');

    expect($src)->toContain("TextEntry::make('ownerUser.name')");
    expect($src)->toContain("'laravel-crm-filament::labels.misc.unallocated'");

    expect($src)->toContain('static::crmCustomFieldEntries($record, false)');
});

it('Lead Qualification section uses grouped entries and hidden gate keyed off grouped FieldValues', function () {
    $src = file_get_contents(
        (new ReflectionClass(LeadResource::class))->getFileName(),
    );

    expect($src)->toContain('static::crmCustomFieldEntries($record, true)');
    expect($src)->toContain('->hidden(function ($record): bool');
    expect($src)->toContain('whereNotNull(\'field_group_id\')');
});

it('Contact section links person/organization through their resources and reads first email/phone', function () {
    $src = file_get_contents(
        (new ReflectionClass(LeadResource::class))->getFileName(),
    );

    expect($src)->toContain("PersonResource::getUrl('view', ['record' => \$record->person])");
    expect($src)->toContain("OrganizationResource::getUrl('view', ['record' => \$record->organization])");
    expect($src)->toContain('LeadDealContactSection::personLabel');
    expect($src)->toContain('$record?->person?->emails()->first()');
    expect($src)->toContain('$record?->person?->phones()->first()');
});

it('LeadDealContactSection::personLabel composes first+last and handles null', function () {
    expect(LeadDealContactSection::personLabel(null))->toBe('');

    $person = new Person([
        'first_name' => 'Jordan',
        'last_name' => 'Lee',
    ]);
    expect(LeadDealContactSection::personLabel($person))->toBe('Jordan Lee');

    $partial = new Person(['first_name' => 'Sam']);
    expect(LeadDealContactSection::personLabel($partial))->toBe('Sam');
});

it('Lead Qualification hidden-gate closure returns true when there are no grouped FieldValues', function () {
    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Qual lead — no groups',
    ]);

    $closure = leadQualificationHiddenClosure();

    expect($closure($lead->fresh()))->toBeTrue();
    expect($closure(null))->toBeTrue();
});

it('Lead Qualification hidden-gate closure returns false when at least one grouped FieldValue exists', function () {
    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Qual lead — with group',
    ]);

    $group = FieldGroup::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Marketing',
        'model' => Lead::class,
    ]);

    $field = Field::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'utm_source',
        'key' => 'utm_source',
        'type' => 'text',
        'field_group_id' => $group->id,
    ]);

    FieldValue::create([
        'external_id' => (string) Str::uuid(),
        'field_id' => $field->id,
        'field_valueable_type' => Lead::class,
        'field_valueable_id' => $lead->id,
        'value' => 'newsletter',
    ]);

    $closure = leadQualificationHiddenClosure();

    expect($closure($lead->fresh()))->toBeFalse();
});

/**
 * Extract the protected `$isHidden` Closure from the Lead Qualification
 * Section, so tests can invoke it directly with a record (bypassing
 * Filament's evaluate() container resolution).
 */
function leadQualificationHiddenClosure(): Closure
{
    $sections = leadInfolistSections(null);
    $qualSection = $sections[1];

    $ref = new ReflectionProperty($qualSection, 'isHidden');
    $ref->setAccessible(true);

    return $ref->getValue($qualSection);
}

/**
 * Build the infolist via Filament Schema mounted on a fresh ViewLead instance,
 * and return the three top-level Section components.
 *
 * @return array<int, Section>
 */
function leadInfolistSections(?Lead $record): array
{
    $page = new ViewLead;

    $schema = Schema::make($page)
        ->statePath('data');

    if ($record !== null) {
        $schema->record($record);
    }

    LeadResource::infolist($schema);

    $components = $schema->getComponents(withHidden: true);

    return array_values(array_filter(
        $components,
        fn ($c) => $c instanceof Section,
    ));
}
