<?php

use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use VentureDrake\LaravelCrm\Models\Address;
use VentureDrake\LaravelCrm\Models\AddressType;
use VentureDrake\LaravelCrm\Models\Email;
use VentureDrake\LaravelCrm\Models\Phone;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrm\Support\PdfContactDetails;
use VentureDrake\LaravelCrmFilament\Auth\Login;
use VentureDrake\LaravelCrmFilament\Pages\GeneralSettings;

/**
 * Mount a GeneralSettings page without invoking the Livewire constructor and
 * build its top-level Section list via the Schema host. Same shape as the
 * crmShowPageContentComponents helper in CrmShowPageLayoutTest.php and the
 * leadInfolistTopLevelSections helper in LeadInfolistTest.php.
 *
 * @return array<int, Section>
 */
function parityGeneralSettingsSections(): array
{
    $page = (new ReflectionClass(GeneralSettings::class))->newInstanceWithoutConstructor();
    $page->data = [];
    $schema = Schema::make($page);
    $schema = $page->form($schema);

    $sections = [];

    // Since the 2.4.1-parity tab split the Sections sit inside the Tabs
    // component: Tabs -> one `default` child schema -> Tabs\Tab -> its own
    // child schema -> the Sections.
    foreach ($schema->getComponents(withHidden: true) as $component) {
        if ($component instanceof Section) {
            $sections[] = $component;

            continue;
        }

        if (! $component instanceof Tabs) {
            continue;
        }

        foreach ($component->getChildSchemas() as $tabsSchema) {
            foreach ($tabsSchema->getComponents(withHidden: true) as $tab) {
                foreach ($tab->getChildSchemas() as $tabSchema) {
                    foreach ($tabSchema->getComponents(withHidden: true) as $child) {
                        if ($child instanceof Section) {
                            $sections[] = $child;
                        }
                    }
                }
            }
        }
    }

    return $sections;
}

it('(a) KEYS map contains exactly the expected 28 scalar keys in order', function (): void {
    // The story prompt names "25-key map" but the implementation enumerates 26
    // scalar settings (3 branding + 6 localisation + 7 prefixes + 6 document
    // defaults + 2 tax + 2 behaviour). The progress notes for US-001 of this
    // series document the discrepancy — the explicit enumeration is
    // authoritative. US-006 adds `primary_color` (read by Auth\Login and the
    // panel stub but previously not editable), taking the map to 27; core
    // 2.4.1's shared `pdf_contact_details` block takes it to 28.
    $keys = array_keys(GeneralSettings::KEYS);

    expect($keys)->toBe([
        // Branding
        'organization_name',
        'vat_number',
        'logo_file',
        'primary_color',
        // Localisation
        'language',
        'country',
        'currency',
        'timezone',
        'date_format',
        'time_format',
        // Prefixes
        'lead_prefix',
        'deal_prefix',
        'quote_prefix',
        'order_prefix',
        'invoice_prefix',
        'delivery_prefix',
        'purchase_order_prefix',
        // Document defaults
        'quote_terms',
        'pdf_contact_details',
        'invoice_contact_details',
        'invoice_terms',
        'invoice_payment_instructions',
        'purchase_order_terms',
        'purchase_order_delivery_instructions',
        // Tax
        'tax_name',
        'tax_rate',
        // Behaviour
        'show_related_activity',
        'dynamic_products',
    ]);

    expect($keys)->toHaveCount(28);
});

it('(a) primary_color is editable and feeds Auth\\Login::brandPrimaryColor()', function (): void {
    expect(GeneralSettings::KEYS)->toHaveKey('primary_color');

    $src = file_get_contents((new ReflectionClass(GeneralSettings::class))->getFileName());
    expect($src)->toContain("ColorPicker::make('primary_color')");

    app('laravel-crm.settings')->set('primary_color', '#ff0066', GeneralSettings::KEYS['primary_color']);
    app('laravel-crm.settings')->forgetCache();

    $login = (new ReflectionClass(Login::class))->newInstanceWithoutConstructor();
    expect($login->brandPrimaryColor())->toBe('#ff0066');
});

it('(a) Auth\\Login::brandPrimaryColor() falls back to the CRM teal when unset', function (): void {
    app('laravel-crm.settings')->forgetCache();

    $login = (new ReflectionClass(Login::class))->newInstanceWithoutConstructor();
    expect($login->brandPrimaryColor())->toBe('#05b3a9');
});

it('(a) every KEYS entry has a non-empty string label for the SettingService::set call', function (): void {
    foreach (GeneralSettings::KEYS as $key => $label) {
        expect($label)->toBeString();
        expect($label)->not->toBeEmpty("Expected a non-empty label for key '{$key}'");
    }
});

it('(b) the scalar sections render in the expected order on the General tab', function (): void {
    $sections = parityGeneralSettingsSections();

    // Was 6 scalar sections. `prefixes` is gone — the seven ID prefixes moved
    // to the entity tab that owns each of them at the 2.4.1-parity split — so
    // the General tab now opens with 5, followed by the 3 contact repeaters.
    expect(count($sections))->toBeGreaterThanOrEqual(5);

    $headings = array_map(fn (Section $s) => $s->getHeading(), array_slice($sections, 0, 5));

    expect($headings)->toBe([
        __('laravel-crm-filament::labels.sections.branding'),
        __('laravel-crm-filament::labels.sections.localisation'),
        __('laravel-crm-filament::labels.sections.document_defaults'),
        __('laravel-crm-filament::labels.sections.tax'),
        __('laravel-crm-filament::labels.sections.behaviour'),
    ]);
});

it('(b) each section heading routes through the labels namespace (source-grep regression guard)', function (): void {
    // Filament v5's Section class has no public getName() accessor, so to lock
    // the section *identifier* (Section::make's first arg) in addition to the
    // rendered heading we grep the source. Cursor-walks the literal
    // `Section::make('X')` strings in order to catch both "section missing"
    // AND "section reordered" with a single assertion per section.
    $src = file_get_contents((new ReflectionClass(GeneralSettings::class))->getFileName());

    $cursor = 0;
    foreach (['branding', 'localisation', 'document_defaults', 'tax', 'behaviour'] as $name) {
        $needle = "Section::make('{$name}')";
        $pos = strpos($src, $needle, $cursor);
        expect($pos)->not->toBeFalse("Expected Section::make('{$name}') after source cursor {$cursor}");
        $cursor = $pos + strlen($needle);

        $headingNeedle = "->heading(__('laravel-crm-filament::labels.sections.{$name}'))";
        expect($src)->toContain($headingNeedle);
    }
});

it('(c) round-trip: full scalar + 1 phone + 1 email + 1 address payload persists through save()', function (): void {
    // Pre-seed the AddressType the address row binds to via the Select FK.
    $addressType = AddressType::create(['name' => 'Headquarters']);

    // Hot-patch subclass — bypasses $this->form->getState() (which requires a
    // real Livewire mount) by reading $this->data directly. Same pattern as
    // ClickSendIntegrationPageTest, LeadNotesRelationManagerTest, etc.
    $page = new class extends GeneralSettings
    {
        public function save(): void
        {
            $data = $this->data;
            $settings = app('laravel-crm.settings');

            foreach (self::KEYS as $key => $label) {
                $settings->set($key, $data[$key] ?? null, $label);
            }

            $anchor = $this->resolveAnchor();
            $this->syncPhones($anchor, $data['phones'] ?? []);
            $this->syncEmails($anchor, $data['emails'] ?? []);
            $this->syncAddresses($anchor, $data['addresses'] ?? []);

            if (method_exists($settings, 'forgetCache')) {
                $settings->forgetCache();
            }
        }
    };

    // Full scalar payload (28 keys) + 1 nested row each.
    $page->data = [
        // Branding
        'organization_name' => 'Acme Inc',
        'vat_number' => 'GB123456789',
        'logo_file' => 'logo.png',
        'primary_color' => '#123456',
        // Localisation
        'language' => 'english',
        'country' => 'United States',
        'currency' => 'USD',
        'timezone' => 'America/New_York',
        'date_format' => 'm/d/Y',
        'time_format' => 'h:i A',
        // Prefixes
        'lead_prefix' => 'L',
        'deal_prefix' => 'D',
        'quote_prefix' => 'Q',
        'order_prefix' => 'O',
        'invoice_prefix' => 'INV',
        'delivery_prefix' => 'DEL',
        'purchase_order_prefix' => 'PO',
        // Document defaults
        'quote_terms' => 'Quote terms body',
        'pdf_contact_details' => 'Shared contact details body',
        'invoice_contact_details' => 'Invoice contact details body',
        'invoice_terms' => 'Invoice terms body',
        'invoice_payment_instructions' => 'Payment instructions body',
        'purchase_order_terms' => 'PO terms body',
        'purchase_order_delivery_instructions' => 'PO delivery instructions body',
        // Tax
        'tax_name' => 'VAT',
        'tax_rate' => '20',
        // Behaviour
        'show_related_activity' => true,
        'dynamic_products' => false,
        // Nested rows
        'phones' => [
            ['id' => null, 'type' => 'work', 'number' => '+1-555-9000'],
        ],
        'emails' => [
            ['id' => null, 'type' => 'work', 'address' => 'team@acme.test'],
        ],
        'addresses' => [
            [
                'id' => null,
                'address_type_id' => $addressType->id,
                'line1' => '1 Wharf St',
                'line2' => 'Suite 200',
                'line3' => null,
                'city' => 'New York',
                'state' => 'NY',
                'code' => '10001',
                'country' => 'US',
            ],
        ],
    ];

    $page->save();

    // (c.1) Every scalar key is retrievable via SettingService::get(...)
    $settings = app('laravel-crm.settings');
    $settings->forgetCache();

    foreach (GeneralSettings::KEYS as $key => $_label) {
        $expected = $page->data[$key];
        $actual = $settings->get($key);

        // Booleans round-trip through the settings table as strings, so cast
        // both sides to string for the comparison.
        if (is_bool($expected)) {
            expect((string) $actual)->toBe($expected ? '1' : '0', "Setting '{$key}' did not round-trip correctly");
        } else {
            expect((string) $actual)->toBe((string) $expected, "Setting '{$key}' did not round-trip correctly");
        }
    }

    // (c.2) The Setting{name='team'} anchor exists and carries the polymorphic
    // morph type the new rows reference.
    $anchor = Setting::where('name', 'team')->first();
    expect($anchor)->not->toBeNull();

    // (c.3) Phone row landed on crm_phones with phoneable_type=Setting,
    // phoneable_id=$anchor->id.
    $phone = Phone::where('number', '+1-555-9000')->first();
    expect($phone)->not->toBeNull();
    expect($phone->phoneable_type)->toBe($anchor->getMorphClass());
    expect((int) $phone->phoneable_id)->toBe((int) $anchor->id);
    expect($phone->type)->toBe('work');

    // (c.4) Email row landed on crm_emails with the correct polymorphic anchor.
    $email = Email::where('address', 'team@acme.test')->first();
    expect($email)->not->toBeNull();
    expect($email->emailable_type)->toBe($anchor->getMorphClass());
    expect((int) $email->emailable_id)->toBe((int) $anchor->id);
    expect($email->type)->toBe('work');

    // (c.5) Address row landed on crm_addresses with the correct polymorphic
    // anchor and the address_type_id Select value preserved.
    $address = Address::where('line1', '1 Wharf St')->first();
    expect($address)->not->toBeNull();
    expect($address->addressable_type)->toBe($anchor->getMorphClass());
    expect((int) $address->addressable_id)->toBe((int) $anchor->id);
    expect((int) $address->address_type_id)->toBe((int) $addressType->id);
    expect($address->city)->toBe('New York');
    expect($address->state)->toBe('NY');
    expect($address->code)->toBe('10001');
    expect($address->country)->toBe('US');
});

/**
 * The shared From block only reaches a PDF if the panel can write it and read
 * it back, and only falls back correctly if clearing it resolves to null rather
 * than to an empty string. `GeneralSettings::save()` writes every key on every
 * save, so a cleared field persists as an empty row — which is exactly the case
 * `PdfContactDetails::for()` guards with `filled()`.
 */
it('round-trips pdf_contact_details through save()/mount() and clears it to null', function (): void {
    $page = new class extends GeneralSettings
    {
        public function save(): void
        {
            $settings = app('laravel-crm.settings');

            foreach (self::KEYS as $key => $label) {
                $settings->set($key, $this->data[$key] ?? null, $label);
            }

            $settings->forgetCache();
        }
    };

    $page->data[PdfContactDetails::SHARED_KEY] = "Acme Inc\n1 Wharf St";
    $page->save();

    expect(app('laravel-crm.settings')->get(PdfContactDetails::SHARED_KEY))->toBe("Acme Inc\n1 Wharf St")
        ->and(PdfContactDetails::for('quote'))->toBe("Acme Inc\n1 Wharf St");

    // mount() rehydrates the field from the stored value.
    $fresh = new class extends GeneralSettings
    {
        public function mount(): void
        {
            $settings = app('laravel-crm.settings');
            foreach (array_keys(self::KEYS) as $key) {
                $this->data[$key] = $settings->get($key);
            }
        }
    };
    $fresh->mount();

    expect($fresh->data[PdfContactDetails::SHARED_KEY])->toBe("Acme Inc\n1 Wharf St");

    // Clearing writes an empty row rather than deleting it, and that empty row
    // must still resolve to null so the chain falls through.
    $page->data[PdfContactDetails::SHARED_KEY] = '';
    $page->save();

    expect((string) app('laravel-crm.settings')->get(PdfContactDetails::SHARED_KEY))->toBe('')
        ->and(PdfContactDetails::for('quote'))->toBeNull();
});
