<?php

use Filament\Panel;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Resources\Teams\CrmTeamResource;

it('honours every module override on the plugin', function (string $module, bool $enabled) {
    $plugin = LaravelCrmPlugin::make()->modules([$module => $enabled]);
    expect($plugin->isModuleEnabled($module))->toBe($enabled);
})->with([
    ['leads', true], ['leads', false],
    ['deals', true], ['deals', false],
    ['quotes', true], ['quotes', false],
    ['orders', true], ['orders', false],
    ['invoices', true], ['invoices', false],
    ['deliveries', true], ['deliveries', false],
    ['purchase-orders', true], ['purchase-orders', false],
    ['email-marketing', true], ['email-marketing', false],
    ['sms-marketing', true], ['sms-marketing', false],
    ['chat', true], ['chat', false],
    ['teams', true], ['teams', false],
]);

it('exposes teams module fluent setters that chain', function (string $setter) {
    expect(LaravelCrmPlugin::make()->{$setter}()->isModuleEnabled('teams'))->toBeTrue();
    expect(LaravelCrmPlugin::make()->{$setter}(false)->isModuleEnabled('teams'))->toBeFalse();

    expect(LaravelCrmPlugin::make()->{$setter}(false))->toBeInstanceOf(LaravelCrmPlugin::class);
})->with(['withTeams', 'teams']);

it('registers CrmTeamResource on the panel when the teams module is enabled', function () {
    $plugin = LaravelCrmPlugin::make()->withTeams();
    $panel = Panel::make()->id('admin-teams-on');
    $plugin->register($panel);

    expect($plugin->getResources())->toContain(CrmTeamResource::class);
    expect($panel->getResources())->toContain(CrmTeamResource::class);
});

it('omits CrmTeamResource from the panel when the teams module is disabled', function () {
    $plugin = LaravelCrmPlugin::make()->withTeams(false);
    $panel = Panel::make()->id('admin-teams-off');
    $plugin->register($panel);

    expect($plugin->getResources())->not->toContain(CrmTeamResource::class);
    expect($panel->getResources())->not->toContain(CrmTeamResource::class);
});

it('gates teams on config(laravel-crm.modules), never on the multi-tenancy config(laravel-crm.teams)', function () {
    // The Jetstream multi-tenancy flag is on but `teams` is not a listed module:
    // the CRM teams module stays off.
    config([
        'laravel-crm.teams' => true,
        'laravel-crm.modules' => ['leads', 'deals'],
    ]);

    expect(LaravelCrmPlugin::make()->isModuleEnabled('teams'))->toBeFalse();

    // ...and the inverse: multi-tenancy off, but `teams` listed as a module.
    config([
        'laravel-crm.teams' => false,
        'laravel-crm.modules' => ['leads', 'deals', 'teams'],
    ]);

    expect(LaravelCrmPlugin::make()->isModuleEnabled('teams'))->toBeTrue();
});

it('registers CrmTeamResource by default because core lists teams in its modules array', function () {
    expect(config('laravel-crm.modules'))->toContain('teams');
    expect(LaravelCrmPlugin::make()->getResources())->toContain(CrmTeamResource::class);
});

it('exposes branding fluent setters that chain', function () {
    $plugin = LaravelCrmPlugin::make()
        ->brand('Acme CRM')
        ->brandLogo('https://example.com/logo.svg')
        ->favicon('https://example.com/favicon.ico')
        ->primaryColor('#FF5733');

    expect($plugin)->toBeInstanceOf(LaravelCrmPlugin::class);
    expect($plugin->getBrand())->toBe('Acme CRM');
});

it('exposes navigationGroup() as a chainable setter', function () {
    $plugin = LaravelCrmPlugin::make()->navigationGroup('CRM');
    expect($plugin->getNavigationGroup())->toBe('CRM');
});
