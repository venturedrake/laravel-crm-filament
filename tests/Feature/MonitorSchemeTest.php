<?php

declare(strict_types=1);

use VentureDrake\LaravelCrm\Models\Monitor;
use VentureDrake\LaravelCrmFilament\Resources\Monitors\MonitorResource;
use VentureDrake\LaravelCrmFilament\Resources\Monitors\Pages\CreateMonitor;
use VentureDrake\LaravelCrmFilament\Resources\Monitors\Pages\EditMonitor;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0 monitor form: the scheme is an https/http selector, the URL input
 * holds only host and path, and the two are joined back up on save.
 */
beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Monitor Scheme Tester',
        'email' => 'monitor-scheme-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);
});

function schemeMonitor(string $url, string $type = 'https'): Monitor
{
    return Monitor::create([
        'name' => 'Site',
        'type' => $type,
        'url' => $url,
        'host' => parse_url($url, PHP_URL_HOST),
        'method' => 'GET',
        'expected_status_code' => 200,
        'interval' => 5,
        'is_active' => true,
    ]);
}

it('splits a pasted scheme off into the type selector', function (string $pasted, string $type, string $url) {
    livewire(CreateMonitor::class)
        ->assertSchemaStateSet(['type' => 'https'])
        ->fillForm(['url' => $pasted])
        ->assertSchemaStateSet(['type' => $type, 'url' => $url]);
})->with([
    'http' => ['http://example.com/health', 'http', 'example.com/health'],
    'upper-case https' => ['HTTPS://example.com', 'https', 'example.com'],
    'no scheme' => ['example.com', 'https', 'example.com'],
]);

it('leaves any other scheme in the URL', function () {
    expect(MonitorResource::splitSchemeUrl('https', 'ftp://example.com'))->toBe(['https', 'ftp://example.com'])
        ->and(MonitorResource::splitSchemeUrl('tcp', 'example.com'))->toBe(['https', 'example.com']);
});

it('saves the URL rebuilt from the type and the host', function () {
    livewire(CreateMonitor::class)
        ->fillForm(['type' => 'http', 'url' => 'example.com/health'])
        ->call('create')
        ->assertHasNoFormErrors();

    $monitor = Monitor::query()->sole();

    expect($monitor->type)->toBe('http')
        ->and($monitor->url)->toBe('http://example.com/health')
        ->and($monitor->host)->toBe('example.com');
});

it('saves a pasted URL even if the scheme was never split in the browser', function () {
    expect(MonitorResource::withSchemeUrl(['type' => 'https', 'url' => 'http://example.com']))
        ->toMatchArray(['type' => 'http', 'url' => 'http://example.com']);
});

it('only offers https and http', function () {
    expect(array_keys(MonitorResource::TYPE_OPTIONS))->toBe(['https', 'http']);

    livewire(CreateMonitor::class)
        ->fillForm(['type' => 'tcp', 'url' => 'example.com'])
        ->call('create')
        ->assertHasFormErrors(['type']);
});

it('splits the stored URL into type and host on edit, the URL\'s scheme winning', function () {
    $monitor = schemeMonitor('http://example.com/status', type: 'https');

    livewire(EditMonitor::class, ['record' => $monitor->external_id])
        ->assertSchemaStateSet(['type' => 'http', 'url' => 'example.com/status']);
});

it('rebuilds scheme://host on save from the edit page', function () {
    $monitor = schemeMonitor('http://example.com/status', type: 'http');

    livewire(EditMonitor::class, ['record' => $monitor->external_id])
        ->fillForm(['type' => 'https'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($monitor->fresh())
        ->type->toBe('https')
        ->url->toBe('https://example.com/status');
});

it('leaves an unchanged URL as stored', function () {
    $monitor = schemeMonitor('https://example.com/status');

    livewire(EditMonitor::class, ['record' => $monitor->external_id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($monitor->fresh()->url)->toBe('https://example.com/status');
});
