<?php

use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Support\PdfContactDetails;
use VentureDrake\LaravelCrmFilament\Pages\GeneralSettings;

it('declares the full 28-key scalar setting map on GeneralSettings', function (): void {
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
        'pdf_contact_details',
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

it('renders 8 Sections on the General tab including the phones/emails/addresses repeaters', function (): void {
    // Was 9. `prefixes` is gone: the seven ID prefixes moved out to the entity
    // tab that owns each of them, which is the point of the split.
    $page = (new ReflectionClass(GeneralSettings::class))->newInstanceWithoutConstructor();
    $components = generalSettingsTopLevelSections($page);
    expect($components)->toHaveCount(8);

    // Section::make's first arg is the section identifier, but Filament v5
    // doesn't expose it as a public getter (no Section::getName). Walk the
    // literal `Section::make('X')` strings via strpos to assert ordering.
    $src = file_get_contents((new ReflectionClass(GeneralSettings::class))->getFileName());

    $cursor = 0;
    foreach (['branding', 'localisation', 'document_defaults', 'tax', 'behaviour', 'phones', 'emails', 'addresses'] as $name) {
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

/**
 * The shared block's field names itself through core's constant rather than a
 * literal, so the panel and core cannot disagree on the settings key — which is
 * the entire mechanism by which `PdfContactDetails::for()` finds the value.
 */
it('uses a Textarea keyed off core PdfContactDetails::SHARED_KEY for the shared contact block', function (): void {
    $src = file_get_contents((new ReflectionClass(GeneralSettings::class))->getFileName());

    expect($src)->toContain('Textarea::make(PdfContactDetails::SHARED_KEY)')
        ->and(PdfContactDetails::SHARED_KEY)->toBe('pdf_contact_details')
        ->and(GeneralSettings::KEYS)->toHaveKey(PdfContactDetails::SHARED_KEY);
});

/**
 * The chain is only usable if both ends explain themselves. Since the tab split
 * the two fields no longer sit next to each other — the shared block is on
 * General, the override is on the Invoices tab — so the helper text is the only
 * thing telling an admin how they relate, and it has to name the other tab.
 */
it('explains the contact-details chain on both ends, across the tab split', function (): void {
    $src = file_get_contents((new ReflectionClass(GeneralSettings::class))->getFileName());

    $shared = strpos($src, 'Textarea::make(PdfContactDetails::SHARED_KEY)');
    $invoice = strpos($src, "Textarea::make('invoice_contact_details')");

    expect($shared)->not->toBeFalse()
        ->and($invoice)->not->toBeFalse();

    // The shared block points forward to the per-type override…
    expect($src)->toContain('unless the document type overrides it on its own tab')
        // …and the override points back at where the shared one lives.
        ->toContain('Overrides the shared contact details on the General tab');
});

it('keeps the shared contact block on General and the override on the Invoices tab', function (): void {
    $page = (new ReflectionClass(GeneralSettings::class))->newInstanceWithoutConstructor();

    expect(generalSettingsFieldNamesOnTab($page, 'general'))
        ->toContain(PdfContactDetails::SHARED_KEY)
        ->not->toContain('invoice_contact_details');

    expect(generalSettingsFieldNamesOnTab($page, 'invoices'))
        ->toContain('invoice_contact_details')
        ->toContain('invoice_prefix')
        ->toContain('invoice_terms')
        ->toContain('invoice_payment_instructions');
});

it('puts every ID prefix on the tab for the entity that owns it', function (): void {
    $page = (new ReflectionClass(GeneralSettings::class))->newInstanceWithoutConstructor();

    foreach (GeneralSettings::ENTITY_TABS as $tab => $config) {
        expect(in_array($config['prefix'], generalSettingsFieldNamesOnTab($page, $tab), true))
            ->toBeTrue("{$config['prefix']} should live on the {$tab} tab");
    }

    // …and nowhere else: a prefix left behind on General would still save, so
    // the split would look done while showing the field twice.
    $general = generalSettingsFieldNamesOnTab($page, 'general');

    foreach (GeneralSettings::ENTITY_TABS as $config) {
        expect($general)->not->toContain($config['prefix']);
    }
});

it('hides an entity tab when its module is off, and shows it when the modules list is empty', function (): void {
    $page = (new ReflectionClass(GeneralSettings::class))->newInstanceWithoutConstructor();

    config(['laravel-crm.modules' => ['leads']]);
    $names = generalSettingsTabNames($page);
    expect($names)->toContain('leads')
        ->not->toContain('invoices')
        ->not->toContain('purchase-orders')
        // General is never gated — it holds settings that belong to no entity.
        ->toContain('general');

    // Core reads an empty modules array as "everything on", so every tab
    // returns rather than the page collapsing to General alone.
    config(['laravel-crm.modules' => []]);
    expect(generalSettingsTabNames($page))
        ->toContain('invoices')
        ->toContain('purchase-orders');
});

it('syncs the active tab to the query string so a tab deep-links', function (): void {
    $page = (new ReflectionClass(GeneralSettings::class))->newInstanceWithoutConstructor();

    expect(generalSettingsTabs($page)?->isTabPersistedInQueryString())->toBeTrue();
});

/**
 * The other half of module gating, and the half a rendering assertion cannot
 * reach.
 *
 * A hidden tab and an *ungated* tab both round-trip their state, because
 * Filament renders every tab into the DOM. A tab whose module is off is neither
 * — `entityTabs()` never builds it, so its fields contribute nothing to
 * `getState()`. With `save()` writing `$data[$key] ?? null` across the whole
 * KEYS map, that null reached `SettingService::set()` and blanked every setting
 * on the missing tab: turning off purchase-orders in `laravel-crm.modules` and
 * then saving anything on the General tab destroyed `purchase_order_prefix`,
 * `purchase_order_terms` and `purchase_order_delivery_instructions` — rows core's
 * own CRM still reads, with no screen left in the panel that could restore them.
 *
 * Before the tab split this could not happen: the prefixes and terms lived in
 * always-rendered Sections.
 */
it('does not blank a disabled module\'s settings when saving another tab', function (): void {
    $settings = app('laravel-crm.settings');

    $settings->set('purchase_order_prefix', 'PO', 'Purchase order prefix');
    $settings->set('purchase_order_terms', 'Supplier terms body', 'Purchase order terms');
    $settings->set('purchase_order_delivery_instructions', 'Dock 3', 'Purchase order delivery instructions');
    $settings->set('quote_terms', 'Quote terms body', 'Quote terms');
    $settings->forgetCache();

    // A configured list that omits purchase-orders — NOT an empty array, which
    // core reads as "everything on".
    config(['laravel-crm.modules' => ['leads', 'deals', 'quotes', 'orders', 'invoices', 'deliveries']]);

    // On a throwaway instance: the tab-name helper rebuilds the form and resets
    // `data` on whatever page it is handed, which would empty the page under
    // test before it ever saved.
    expect(generalSettingsTabNames((new ReflectionClass(GeneralSettings::class))->newInstanceWithoutConstructor()))
        ->not->toContain('purchase-orders')
        ->toContain('quotes');

    $page = new class extends GeneralSettings
    {
        // The settings-page permission gate has its own tests; what is under
        // test here is what save() writes once allowed.
        public static function canEditCrmSettings(): bool
        {
            return true;
        }
    };

    $page->mount();

    // Edit something on General and save, exactly as an admin would.
    $page->data['organization_name'] = 'Acme Inc';
    $page->save();

    $settings->forgetCache();

    expect($settings->get('organization_name'))->toBe('Acme Inc')
        // The tab that was never built keeps every one of its values.
        ->and($settings->get('purchase_order_prefix'))->toBe('PO')
        ->and($settings->get('purchase_order_terms'))->toBe('Supplier terms body')
        ->and($settings->get('purchase_order_delivery_instructions'))->toBe('Dock 3')
        // …and a tab that *was* built still round-trips normally.
        ->and($settings->get('quote_terms'))->toBe('Quote terms body');
});

/**
 * The inverse, so the fix is a skip and not a blanket "never write null":
 * clearing a field on a tab that is on screen has to persist as a clear.
 */
it('still clears a rendered field that the user emptied', function (): void {
    $settings = app('laravel-crm.settings');

    $settings->set('quote_terms', 'Quote terms body', 'Quote terms');
    $settings->forgetCache();

    config(['laravel-crm.modules' => ['quotes']]);

    $page = new class extends GeneralSettings
    {
        public static function canEditCrmSettings(): bool
        {
            return true;
        }
    };

    $page->mount();

    $page->data['quote_terms'] = '';
    $page->save();

    $settings->forgetCache();

    expect((string) $settings->get('quote_terms'))->toBe('');
});

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

it('save() writes every rendered KEYS entry through SettingService::set with the entry label', function (): void {
    $src = file_get_contents((new ReflectionClass(GeneralSettings::class))->getFileName());
    expect($src)
        ->toContain('$settings->set($key, $data[$key], $label);')
        // Was `$data[$key] ?? null`, which blanked every setting on a tab whose
        // module is off — those fields are never built, so getState() has no
        // entry for them. The behavioural half is two tests above.
        ->not->toContain('$settings->set($key, $data[$key] ?? null, $label);')
        ->toContain('if (! array_key_exists($key, $data)) {')
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
/**
 * The page's Sections, which since the 2.4.1-parity tab split live inside the
 * Tabs component rather than at the top of the schema.
 *
 * @return array<int, Section>
 */
function generalSettingsTopLevelSections(GeneralSettings $page): array
{
    $sections = [];

    foreach (generalSettingsTabComponents($page) as $tab) {
        foreach ($tab->getChildSchemas() as $schema) {
            foreach ($schema->getComponents(withHidden: true) as $component) {
                if ($component instanceof Section) {
                    $sections[] = $component;
                }
            }
        }
    }

    return $sections;
}

/**
 * The Tabs component the whole form now hangs off.
 */
function generalSettingsTabs(GeneralSettings $page): ?Tabs
{
    $schema = Schema::make($page);
    $page->data = [];
    $schema = $page->form($schema);

    foreach ($schema->getComponents(withHidden: true) as $component) {
        if ($component instanceof Tabs) {
            return $component;
        }
    }

    return null;
}

/**
 * Every Tabs\Tab on the page, in order.
 *
 * Filament nests these one level deeper than it looks: Tabs has a single
 * `default` child schema whose components are the Tab objects.
 *
 * @return array<int, Tabs\Tab>
 */
function generalSettingsTabComponents(GeneralSettings $page): array
{
    $tabs = [];

    foreach (generalSettingsTabs($page)?->getChildSchemas() ?? [] as $schema) {
        foreach ($schema->getComponents(withHidden: true) as $component) {
            if ($component instanceof Tabs\Tab) {
                $tabs[] = $component;
            }
        }
    }

    return $tabs;
}

/**
 * A tab's identifier, recovered from the component id Filament builds from it
 * (`data.purchase-orders::data::tab`). Tab has no accessor for the name it was
 * made with, and the label is translated, so neither is a stable key to assert
 * against.
 */
function generalSettingsTabName(Tabs\Tab $tab): string
{
    $id = (string) $tab->getId();

    return Str::before(Str::after($id, 'data.'), '::');
}

/**
 * The field names on one tab.
 *
 * @return array<int, string>
 */
function generalSettingsFieldNamesOnTab(GeneralSettings $page, string $tab): array
{
    $wanted = Str::slug($tab);

    foreach (generalSettingsTabComponents($page) as $component) {
        if (generalSettingsTabName($component) !== $wanted) {
            continue;
        }

        $names = [];

        foreach ($component->getChildSchemas() as $schema) {
            foreach ($schema->getFlatComponents(withHidden: true) as $field) {
                if (method_exists($field, 'getName')) {
                    $names[] = $field->getName();
                }
            }
        }

        return array_values(array_filter($names));
    }

    return [];
}

/**
 * The identifiers of the tabs currently rendered, in order.
 *
 * @return array<int, string>
 */
function generalSettingsTabNames(GeneralSettings $page): array
{
    return array_map(
        fn (Tabs\Tab $tab): string => generalSettingsTabName($tab),
        generalSettingsTabComponents($page),
    );
}
