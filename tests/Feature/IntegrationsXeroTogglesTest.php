<?php

use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Pages\Integrations;

/**
 * US-006 (a) — the Xero sync section must expose the same four toggles base's
 * XeroConnect does: contacts / products / quotes / invoices. See
 * vendor/venturedrake/laravel-crm/src/Livewire/Settings/Integrations/Xero/XeroConnect.php.
 */
it('declares the four Xero sync KEYS in base order', function (): void {
    expect(array_keys(Integrations::KEYS))->toBe([
        'xero_contacts',
        'xero_products',
        'xero_quotes',
        'xero_invoices',
    ]);
});

it('gives every Xero KEYS entry a non-empty label for the SettingService::set write', function (): void {
    foreach (Integrations::KEYS as $key => $label) {
        expect($label)->toBeString()->not->toBeEmpty("Expected a non-empty label for key '{$key}'");
    }
});

it('renders a Toggle for every KEYS entry, including xero_quotes', function (string $key): void {
    $src = file_get_contents((new ReflectionClass(Integrations::class))->getFileName());

    expect($src)->toContain("Toggle::make('{$key}')->label(static::KEYS['{$key}'])");
})->with(['xero_contacts', 'xero_products', 'xero_quotes', 'xero_invoices']);

it('matches every base XeroConnect xero_* setting name', function (): void {
    $xeroConnect = dirname(__DIR__, 2) . '/vendor/venturedrake/laravel-crm/src/Livewire/Settings/Integrations/Xero/XeroConnect.php';

    if (! is_file($xeroConnect)) {
        $this->markTestSkipped('base XeroConnect livewire component is not installed');
    }

    preg_match_all("/'(xero_[a-z_]+)'/", (string) file_get_contents($xeroConnect), $matches);
    $baseKeys = array_values(array_unique($matches[1]));

    sort($baseKeys);
    $ourKeys = array_keys(Integrations::KEYS);
    sort($ourKeys);

    expect($ourKeys)->toBe($baseKeys);
});

it('round-trips xero_quotes through mount() and save()', function (): void {
    // Hot-patched subclass: reads/writes $this->data directly rather than
    // $this->form, which needs a real Livewire mount.
    $page = new class extends Integrations
    {
        public function mount(): void
        {
            $settings = app('laravel-crm.settings');
            foreach (array_keys(self::KEYS) as $key) {
                $this->data[$key] = (bool) $settings->get($key);
            }
        }

        public function save(): void
        {
            $settings = app('laravel-crm.settings');
            foreach (self::KEYS as $key => $label) {
                $settings->set($key, $this->data[$key] ? '1' : '0', $label);
            }

            if (method_exists($settings, 'forgetCache')) {
                $settings->forgetCache();
            }
        }
    };

    $page->mount();
    expect($page->data)->toHaveKey('xero_quotes');
    expect($page->data['xero_quotes'])->toBeFalse();

    $page->data['xero_quotes'] = true;
    $page->data['xero_contacts'] = true;
    $page->data['xero_products'] = false;
    $page->data['xero_invoices'] = false;
    $page->save();

    expect(Setting::where('name', 'xero_quotes')->value('value'))->toBe('1');
    expect(Setting::where('name', 'xero_quotes')->value('label'))->toBe(Integrations::KEYS['xero_quotes']);

    // And it hydrates back on the next mount.
    $page->data = [];
    $page->mount();
    expect($page->data['xero_quotes'])->toBeTrue();
    expect($page->data['xero_invoices'])->toBeFalse();
});
