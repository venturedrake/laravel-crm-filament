<?php

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Support\PdfTemplateRegistry;
use VentureDrake\LaravelCrmFilament\Pages\GeneralSettings;
use VentureDrake\LaravelCrmFilament\Pages\TemplateSettings;
use VentureDrake\LaravelCrmFilament\Pages\Updates;
use VentureDrake\LaravelCrmFilament\Support\PdfTemplatePreview;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * Reproduce a panel-only install, where core's settings routes never load.
 */
function forgetTemplatePreviewRoute(): void
{
    $remaining = new RouteCollection;

    foreach (Route::getRoutes() as $route) {
        if ($route->getName() === 'laravel-crm.settings.templates.preview') {
            continue;
        }

        $remaining->add($route);
    }

    app('router')->setRoutes($remaining);
}

/**
 * Settings → Templates. Everything on this page is served from the page
 * itself: core's preview and thumbnail routes sit inside the
 * `laravel-crm.user_interface` gate, so they 404 for exactly the headless
 * hosts this plugin serves.
 */
beforeEach(function () {
    RoleSeeder::seed();

    PdfTemplateRegistry::forgetPublishedOverrides();
    PdfTemplatePreview::forgetThumbnails();

    $this->admin = User::create([
        'name' => 'Templates Admin',
        'email' => 'templates-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->admin->givePermissionTo('view crm settings');
    $this->admin->givePermissionTo('edit crm settings');

    $this->actingAs($this->admin->fresh());
});

it('hydrates one selection per doc type', function () {
    $instance = livewire(TemplateSettings::class)->instance();

    expect(array_keys($instance->selected))->toBe(PdfTemplateRegistry::DOC_TYPES);

    foreach ($instance->selected as $slug) {
        expect(PdfTemplateRegistry::all())->toHaveKey($slug);
    }
});

it('writes all five keys together, byte-compatible with core', function () {
    livewire(TemplateSettings::class)
        ->call('select', 'invoice', 'bold')
        ->call('select', 'purchase-order', 'compact')
        ->call('save');

    app('laravel-crm.settings')->forgetCache();

    $settings = app('laravel-crm.settings');

    expect($settings->get('pdf_template_invoice'))->toBe('bold')
        // The hyphen is load-bearing: settingKey() keeps it, and a mismatch
        // silently ignores the admin's choice for exactly this doc type.
        ->and($settings->get('pdf_template_purchase-order'))->toBe('compact')
        // Every key is written on save, not just the ones that changed.
        ->and($settings->get('pdf_template_quote'))->not->toBeNull()
        ->and($settings->get('pdf_template_order'))->not->toBeNull()
        ->and($settings->get('pdf_template_delivery'))->not->toBeNull();
});

it('agrees with the registry on the setting key', function () {
    expect(TemplateSettings::settingKey('purchase-order'))->toBe('pdf_template_purchase-order')
        ->and(TemplateSettings::settingKey('purchase-order'))
        ->toBe(PdfTemplateRegistry::settingKey('purchase-order'));
});

it('ignores an unknown doc type or slug rather than persisting it', function () {
    $component = livewire(TemplateSettings::class)
        ->call('select', 'not-a-doc-type', 'bold')
        ->call('select', 'invoice', 'not-a-template');

    expect($component->instance()->selected)->not->toHaveKey('not-a-doc-type')
        ->and($component->instance()->selected['invoice'])->not->toBe('not-a-template');
});

it('lets a view-only user read the page but not save', function () {
    $viewer = User::create([
        'name' => 'Templates Viewer',
        'email' => 'templates-viewer-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $viewer->givePermissionTo('view crm settings');

    $this->actingAs($viewer->fresh());

    expect(TemplateSettings::canAccess())->toBeTrue()
        ->and(TemplateSettings::canEditCrmSettings())->toBeFalse();

    livewire(TemplateSettings::class)
        ->call('save')
        ->assertStatus(403);
});

it('denies the page to a user without view crm settings', function () {
    $stranger = User::create([
        'name' => 'Templates Stranger',
        'email' => 'templates-stranger-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);

    $this->actingAs($stranger->fresh());

    expect(TemplateSettings::canAccess())->toBeFalse();
});

it('inlines thumbnails rather than linking a route that can 404', function () {
    $instance = livewire(TemplateSettings::class)->instance();

    foreach (array_keys(PdfTemplateRegistry::all()) as $slug) {
        $thumbnail = $instance->thumbnail($slug);

        // Null is acceptable (the artwork may not be published in the test
        // fixture); a URL is not.
        if ($thumbnail !== null) {
            expect($thumbnail)->toStartWith('data:image/svg+xml;base64,');
        }
    }

    $source = (string) file_get_contents((new ReflectionClass(TemplateSettings::class))->getFileName());
    expect($source)->not->toContain('settings.templates.thumbnail');
});

it('serves the preview from an action, not a route', function () {
    $instance = livewire(TemplateSettings::class)->instance();

    expect($instance->previewAction()->getName())->toBe('preview');

    // Core's route only exists while laravel-crm.user_interface is on (the
    // suite turns it on), so the "open in a new tab" extra is offered here and
    // absent on a headless host — never depended on either way.
    expect(Route::has('laravel-crm.settings.templates.preview'))->toBeTrue();
    expect($instance->externalPreviewUrl('invoice', 'bold'))->toContain('bold');

    config()->set('laravel-crm.user_interface', false);
    forgetTemplatePreviewRoute();

    expect($instance->externalPreviewUrl('invoice', 'bold'))->toBeNull();
});

it('renders a real PDF for every doc type and slug pair', function () {
    foreach (PdfTemplateRegistry::DOC_TYPES as $docType) {
        foreach (array_keys(PdfTemplateRegistry::all()) as $slug) {
            $pdf = PdfTemplatePreview::render($docType, $slug);

            expect($pdf)->not->toBeNull()
                ->and(substr((string) $pdf, 0, 4))->toBe('%PDF');
        }
    }
});

it('sends the preview inline so the browser renders it', function () {
    $instance = livewire(TemplateSettings::class)->instance();

    $response = $instance->previewAction()->call([
        'arguments' => ['docType' => 'invoice', 'slug' => 'bold'],
    ]);

    // The disposition is the whole point of a *preview*. streamDownload()
    // builds it from its 4th argument, applied after the headers array — so a
    // `Content-Disposition: inline` header was silently overwritten with the
    // default `attachment`, and Preview downloaded a file instead of showing
    // one.
    expect($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('Content-Type'))->toBe('application/pdf');
});

it('returns null for an unknown doc type or slug rather than throwing', function () {
    expect(PdfTemplatePreview::render('not-a-doc-type', 'bold'))->toBeNull()
        ->and(PdfTemplatePreview::render('invoice', 'not-a-template'))->toBeNull();
});

it('locks A4 portrait on the preview, without which 40% of the page is blank', function () {
    $source = (string) file_get_contents((new ReflectionClass(PdfTemplatePreview::class))->getFileName());

    expect($source)->toContain("->setPaper('a4', 'portrait')")
        ->toContain("'fontDir' => public_path('vendor/laravel-crm/fonts')");
});

it('warns when saving would retire a published-and-edited PDF view', function () {
    $instance = livewire(TemplateSettings::class)->instance();

    $overridden = $instance->overriddenDocTypes();

    expect(array_keys($overridden))->toBe(PdfTemplateRegistry::DOC_TYPES);

    foreach ($overridden as $flag) {
        expect($flag)->toBeBool();
    }
});

it('sorts directly below General settings in the Settings group', function () {
    $sort = fn (string $page): ?int => tap(
        new ReflectionProperty($page, 'navigationSort'),
        fn ($p) => $p->setAccessible(true),
    )->getValue();

    // General settings is 10 and Roles is 20; Templates belongs with the
    // branding-adjacent screens, not at the bottom of the group.
    expect($sort(TemplateSettings::class))->toBe(15)
        ->and($sort(TemplateSettings::class))->toBeGreaterThan($sort(GeneralSettings::class))
        ->and($sort(TemplateSettings::class))->toBeLessThan($sort(Updates::class));
});
