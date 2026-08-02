<?php

use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use VentureDrake\LaravelCrmFilament\Pages\GeneralSettings;

it('declares the full 27-key scalar setting map on GeneralSettings', function (): void {
    $keys = array_keys(GeneralSettings::KEYS);

    expect($keys)->toBe([
        'organization_name',
        'vat_number',
        'logo_file',
        'primary_color',
        'language',
        'country',
        'currency',
        'timezone',
        'date_format',
        'time_format',
        'lead_prefix',
        'deal_prefix',
        'quote_prefix',
        'order_prefix',
        'invoice_prefix',
        'delivery_prefix',
        'purchase_order_prefix',
        'quote_terms',
        'invoice_contact_details',
        'invoice_terms',
        'invoice_payment_instructions',
        'purchase_order_terms',
        'purchase_order_delivery_instructions',
        'tax_name',
        'tax_rate',
        'show_related_activity',
        'dynamic_products',
    ]);
});

it('renders 9 top-level Sections including the phones/emails/addresses repeaters', function (): void {
    $page = (new ReflectionClass(GeneralSettings::class))->newInstanceWithoutConstructor();
    $components = generalSettingsTopLevelSections($page);
    expect($components)->toHaveCount(9);

    // Section::make's first arg is the section identifier, but Filament v5
    // doesn't expose it as a public getter (no Section::getName). Walk the
    // literal `Section::make('X')` strings via strpos to assert ordering.
    $src = file_get_contents((new ReflectionClass(GeneralSettings::class))->getFileName());

    $cursor = 0;
    foreach (['branding', 'localisation', 'prefixes', 'document_defaults', 'tax', 'behaviour', 'phones', 'emails', 'addresses'] as $name) {
        $needle = "Section::make('{$name}')";
        $pos = strpos($src, $needle, $cursor);
        expect($pos)->not->toBeFalse("expected Section::make('{$name}') after cursor {$cursor}");
        $cursor = $pos + strlen($needle);
    }
});

it('each Section heading routes through the labels namespace', function (string $sectionName, string $key): void {
    $src = file_get_contents((new ReflectionClass(GeneralSettings::class))->getFileName());
    expect($src)->toContain("Section::make('{$sectionName}')")
        ->toContain("->heading(__('laravel-crm-filament::labels.sections.{$key}'))");
})->with([
    'branding' => ['branding', 'branding'],
    'localisation' => ['localisation', 'localisation'],
    'prefixes' => ['prefixes', 'prefixes'],
    'document_defaults' => ['document_defaults', 'document_defaults'],
    'tax' => ['tax', 'tax'],
    'behaviour' => ['behaviour', 'behaviour'],
]);

it('uses Toggle for the two boolean keys', function (): void {
    $src = file_get_contents((new ReflectionClass(GeneralSettings::class))->getFileName());

    expect($src)->toContain("Toggle::make('show_related_activity')")
        ->toContain("Toggle::make('dynamic_products')");
});

it('uses Textarea for every *_terms / *_instructions / invoice_contact_details key', function (string $key): void {
    $src = file_get_contents((new ReflectionClass(GeneralSettings::class))->getFileName());
    expect($src)->toContain("Textarea::make('{$key}')");
})->with([
    'quote_terms',
    'invoice_contact_details',
    'invoice_terms',
    'invoice_payment_instructions',
    'purchase_order_terms',
    'purchase_order_delivery_instructions',
]);

it('uses Select for language/country/currency/timezone/date_format/time_format', function (string $key): void {
    $src = file_get_contents((new ReflectionClass(GeneralSettings::class))->getFileName());
    expect($src)->toContain("Select::make('{$key}')");
})->with([
    'language',
    'country',
    'currency',
    'timezone',
    'date_format',
    'time_format',
]);

it('sources Select options for country/currency/timezone/date_format/time_format from the SelectOptions helpers', function (): void {
    $src = file_get_contents((new ReflectionClass(GeneralSettings::class))->getFileName());

    expect($src)
        ->toContain('VentureDrake\\LaravelCrm\\Http\\Helpers\\SelectOptions\\countries')
        ->toContain('VentureDrake\\LaravelCrm\\Http\\Helpers\\SelectOptions\\currencies')
        ->toContain('VentureDrake\\LaravelCrm\\Http\\Helpers\\SelectOptions\\timezones')
        ->toContain('VentureDrake\\LaravelCrm\\Http\\Helpers\\SelectOptions\\dateFormats')
        ->toContain('VentureDrake\\LaravelCrm\\Http\\Helpers\\SelectOptions\\timeFormats');
});

it('save() writes every KEYS entry through SettingService::set with the entry label', function (): void {
    $src = file_get_contents((new ReflectionClass(GeneralSettings::class))->getFileName());
    expect($src)
        ->toContain('$settings->set($key, $data[$key] ?? null, $label);')
        ->toContain('Notification::make()')
        ->toContain('->success()')
        ->toContain('->send();');
});

it('builds the language options from the plugin and base lang directories', function (): void {
    $method = new ReflectionMethod(GeneralSettings::class, 'languageOptions');
    $method->setAccessible(true);
    /** @var array<string, string> $options */
    $options = $method->invoke(null);

    // `en` normalises to `english` so the value base seeds keeps resolving.
    expect($options)->toHaveKey('english');
    expect($options)->not->toHaveKey('en');
    expect(array_key_first($options))->toBe('english');

    // Shipped by this plugin: resources/lang/{en,es,fr}
    expect($options)->toHaveKey('es');
    expect($options)->toHaveKey('fr');

    // Shipped by base: vendor/venturedrake/laravel-crm/resources/lang/{en,en_au,en_gb}
    expect($options)->toHaveKey('en_au');
    expect($options)->toHaveKey('en_gb');

    foreach ($options as $code => $label) {
        expect($label)->toBeString()->not->toBeEmpty("Expected a label for locale '{$code}'");
    }
});

it('no longer hardcodes the language select to English only', function (): void {
    $src = file_get_contents((new ReflectionClass(GeneralSettings::class))->getFileName());

    expect($src)->toContain('->options(static::languageOptions())')
        ->and($src)->not->toContain("->options(['english' => 'English'])");
});

it('every scanned lang directory yields an option key', function (string $locale): void {
    $method = new ReflectionMethod(GeneralSettings::class, 'languageOptions');
    $method->setAccessible(true);
    $options = $method->invoke(null);

    $expected = $locale === 'en' ? 'english' : $locale;
    expect($options)->toHaveKey($expected);
})->with(['en', 'es', 'fr', 'en_au', 'en_gb']);

it('translation keys for the 3 new sections exist in en/fr/es labels.php', function (string $locale): void {
    $path = dirname(__DIR__, 2) . "/resources/lang/{$locale}/labels.php";
    $labels = require $path;

    expect($labels['sections']['localisation'] ?? null)->toBeString()->not->toBeEmpty();
    expect($labels['sections']['document_defaults'] ?? null)->toBeString()->not->toBeEmpty();
    expect($labels['sections']['behaviour'] ?? null)->toBeString()->not->toBeEmpty();
})->with(['en', 'fr', 'es']);

/**
 * @return array<int, Section>
 */
function generalSettingsTopLevelSections(GeneralSettings $page): array
{
    $schema = Schema::make($page);
    $page->data = [];
    $schema = $page->form($schema);

    return array_values(array_filter(
        $schema->getComponents(withHidden: true),
        fn ($c) => $c instanceof Section
    ));
}
