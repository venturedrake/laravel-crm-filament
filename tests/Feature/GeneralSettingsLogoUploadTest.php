<?php

use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Auth\Login;
use VentureDrake\LaravelCrmFilament\Pages\GeneralSettings;
use VentureDrake\LaravelCrmFilament\Support\LogoUrl;

/**
 * US-006 (b) — the logo is an image upload, not a bare TextInput.
 *
 * Base uploads it via `WithFileUploads` and writes `logo_file` (disk-relative
 * path) + `logo_file_name` (filename) — see
 * vendor/venturedrake/laravel-crm/src/Livewire/Settings/SettingEdit.php:322-333.
 * The plugin's Filament panel is not multi-tenant, so the non-tenant
 * `laravel-crm/` path is the one that applies.
 */
beforeEach(function (): void {
    Storage::fake('public');
});

/**
 * Locate the logo uploader inside the branding Section.
 */
function generalSettingsLogoField(): FileUpload
{
    $page = (new ReflectionClass(GeneralSettings::class))->newInstanceWithoutConstructor();
    $page->data = [];
    $schema = $page->form(Schema::make($page));

    $found = null;
    $walk = function (array $components) use (&$walk, &$found): void {
        foreach ($components as $component) {
            if ($component instanceof FileUpload && $component->getName() === 'logo_upload') {
                $found = $component;

                return;
            }

            if (method_exists($component, 'getDefaultChildComponents')) {
                $children = $component->getDefaultChildComponents();
                $walk($children instanceof Schema
                    ? $children->getComponents(withHidden: true)
                    : (array) $children);
            }
        }
    };
    $walk($schema->getComponents(withHidden: true));

    expect($found)->not->toBeNull('Expected a FileUpload named logo_upload in the branding section');

    return $found;
}

/**
 * GeneralSettings subclass that reads `$this->data` instead of
 * `$this->form->getState()` — the standard workaround in this suite for
 * exercising a Filament Page's save() without a real Livewire mount.
 */
function generalSettingsLogoPage(): GeneralSettings
{
    return new class extends GeneralSettings
    {
        public function save(): void
        {
            $data = $this->data;
            $settings = app('laravel-crm.settings');

            foreach (self::KEYS as $key => $label) {
                if ($key === 'logo_file') {
                    continue;
                }

                $settings->set($key, $data[$key] ?? null, $label);
            }

            $this->saveLogo($settings, $data['logo_upload'] ?? null);

            if (method_exists($settings, 'forgetCache')) {
                $settings->forgetCache();
            }
        }
    };
}

it('replaces the bare logo_file TextInput with a FileUpload', function (): void {
    $src = file_get_contents((new ReflectionClass(GeneralSettings::class))->getFileName());

    expect($src)->toContain("FileUpload::make('logo_upload')")
        ->and($src)->not->toContain("TextInput::make('logo_file')");
});

it('configures the uploader as an image capped at 1024KB on the public disk', function (): void {
    $field = generalSettingsLogoField();

    expect($field->getMaxSize())->toBe(1024);
    expect($field->getDiskName())->toBe('public');
    expect($field->getDirectory())->toBe(GeneralSettings::LOGO_DIRECTORY);
    // Raster formats only. FileUpload::image() alone would set the wildcard
    // `image/*`, which validates image/svg+xml through — and an SVG on the
    // public disk is script-bearing markup served same-origin.
    expect($field->getAcceptedFileTypes())
        ->toContain('image/png')
        ->toContain('image/jpeg')
        ->toContain('image/gif')
        ->toContain('image/webp')
        ->not->toContain('image/*')
        ->not->toContain('image/svg+xml');
    expect($field->isMultiple())->toBeFalse();
});

it('stores the logo under the non-tenant laravel-crm directory', function (): void {
    // The panel is not multi-tenant (no ->tenant() call in the plugin), so the
    // non-tenant path base uses is the correct one.
    expect(GeneralSettings::LOGO_DIRECTORY)->toBe('laravel-crm');

    $pluginSrc = file_get_contents(dirname(__DIR__, 2) . '/src/LaravelCrmPlugin.php');
    expect($pluginSrc)->not->toContain('->tenant(');
});

it('save() writes both logo_file and logo_file_name from the uploaded path', function (): void {
    $page = generalSettingsLogoPage();
    $page->data = [
        'logo_upload' => 'laravel-crm/acme-logo.png',
    ];

    $page->save();

    $settings = app('laravel-crm.settings');
    $settings->forgetCache();

    expect($settings->get('logo_file'))->toBe('laravel-crm/acme-logo.png');
    expect($settings->get(GeneralSettings::LOGO_FILE_NAME_KEY))->toBe('acme-logo.png');
});

it('save() leaves an existing logo_file alone when nothing was uploaded', function (): void {
    $settings = app('laravel-crm.settings');
    $settings->set('logo_file', 'laravel-crm/existing.png', 'Logo file');
    $settings->forgetCache();

    $page = generalSettingsLogoPage();
    $page->data = ['logo_upload' => null];
    $page->save();

    $settings->forgetCache();
    expect($settings->get('logo_file'))->toBe('laravel-crm/existing.png');
});

it('an actual upload lands on the faked public disk and round-trips through the settings', function (): void {
    $file = UploadedFile::fake()->image('acme.png', 200, 60);
    Storage::disk('public')->putFileAs(GeneralSettings::LOGO_DIRECTORY, $file, 'acme.png');

    Storage::disk('public')->assertExists('laravel-crm/acme.png');

    $page = generalSettingsLogoPage();
    $page->data = ['logo_upload' => 'laravel-crm/acme.png'];
    $page->save();

    $settings = app('laravel-crm.settings');
    $settings->forgetCache();

    expect($settings->get('logo_file'))->toBe('laravel-crm/acme.png');
    expect(Setting::where('name', 'logo_file_name')->value('value'))->toBe('acme.png');
});

it('mount() hydrates the uploader from the stored logo_file path', function (): void {
    $src = file_get_contents((new ReflectionClass(GeneralSettings::class))->getFileName());

    expect($src)->toContain("\$this->data['logo_upload'] = \$this->data['logo_file'] ?? null;");
});

it('Login::brandLogoUrl() renders the uploaded logo through the public disk', function (): void {
    Storage::disk('public')->put('laravel-crm/acme.png', 'png-bytes');

    app('laravel-crm.settings')->set('logo_file', 'laravel-crm/acme.png', 'Logo file');
    app('laravel-crm.settings')->forgetCache();

    $method = new ReflectionMethod(Login::class, 'brandLogoUrl');
    $method->setAccessible(true);
    $url = $method->invoke((new ReflectionClass(Login::class))->newInstanceWithoutConstructor());

    expect($url)->toBe(Storage::disk('public')->url('laravel-crm/acme.png'));
    expect($url)->toContain('laravel-crm/acme.png');
});

it('LogoUrl passes fully-qualified URLs through and resolves blank to null', function (): void {
    expect(LogoUrl::resolve('https://cdn.example.test/logo.png'))->toBe('https://cdn.example.test/logo.png');
    expect(LogoUrl::resolve('http://cdn.example.test/logo.png'))->toBe('http://cdn.example.test/logo.png');
    expect(LogoUrl::resolve(null))->toBeNull();
    expect(LogoUrl::resolve(''))->toBeNull();
});

it('the panel stub and plugin resolve logo_file through LogoUrl too', function (): void {
    $stub = file_get_contents(dirname(__DIR__, 2) . '/stubs/CrmPanelProvider.php.stub');
    expect($stub)->toContain('LogoUrl::resolve($brandLogo)');

    $plugin = file_get_contents(dirname(__DIR__, 2) . '/src/LaravelCrmPlugin.php');
    expect($plugin)->toContain("LogoUrl::resolve(\$settings?->get('logo_file'))");
});
