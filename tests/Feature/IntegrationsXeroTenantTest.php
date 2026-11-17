<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use VentureDrake\LaravelCrmFilament\Pages\Integrations;
use VentureDrake\LaravelCrmFilament\Support\XeroIntegration;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0's XeroConnect names the organisation the CRM is linked to. The
 * name is read through the package only behind XeroIntegration::connected().
 */
beforeEach(function () {
    RoleSeeder::seed();

    $admin = User::create([
        'name' => 'Xero Tenant Admin',
        'email' => 'xero-tenant-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $admin->givePermissionTo('view crm settings');
    $admin->givePermissionTo('edit crm settings');

    $this->actingAs($admin->fresh());

    XeroIntegration::$installedUsing = true;
});

afterEach(function () {
    XeroIntegration::$installedUsing = null;
});

function xeroTenantStub(bool $connected, ?string $tenant, bool $throws = false): object
{
    $stub = new class($connected, $tenant, $throws)
    {
        public int $tenantCalls = 0;

        public function __construct(private bool $connected, private ?string $tenant, private bool $throws) {}

        public function isConnected(): bool
        {
            return $this->connected;
        }

        public function getTenantName(): ?string
        {
            $this->tenantCalls++;

            if ($this->throws) {
                throw new RuntimeException('token expired');
            }

            return $this->tenant;
        }
    };

    app()->instance('xero', $stub);

    return $stub;
}

it('shows which Xero organisation the CRM is connected to', function () {
    xeroTenantStub(true, 'Acme Pty Ltd');

    livewire(Integrations::class)
        ->assertOk()
        ->assertSee(__('laravel-crm-filament::labels.integrations.xero_connected_to', ['name' => 'Acme Pty Ltd']));
});

it('does not ask Xero for the name when not connected', function () {
    $stub = xeroTenantStub(false, 'Acme Pty Ltd');

    expect(XeroIntegration::tenantName())->toBeNull()
        ->and($stub->tenantCalls)->toBe(0);
});

it('reads a failing or blank tenant lookup as no name', function (bool $throws, ?string $tenant) {
    xeroTenantStub(true, $tenant, $throws);

    expect(XeroIntegration::tenantName())->toBeNull();

    livewire(Integrations::class)
        ->assertOk()
        ->assertDontSee(__('laravel-crm-filament::labels.integrations.xero_connected_to', ['name' => '']));
})->with([
    'throws' => [true, 'Acme'],
    'blank' => [false, ''],
    'null' => [false, null],
]);
