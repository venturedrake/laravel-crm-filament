<?php

declare(strict_types=1);

use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use VentureDrake\LaravelCrm\Models\Product;
use VentureDrake\LaravelCrm\Models\TaxRate;
use VentureDrake\LaravelCrmFilament\Pages\GeneralSettings;
use VentureDrake\LaravelCrmFilament\Resources\TaxRates\Pages\CreateTaxRate;
use VentureDrake\LaravelCrmFilament\Resources\TaxRates\Pages\EditTaxRate;
use VentureDrake\LaravelCrmFilament\Resources\TaxRates\Pages\ListTaxRates;
use VentureDrake\LaravelCrmFilament\Resources\TaxRates\Pages\ViewTaxRate;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0 bounds a tax rate to 0–100 and refuses to delete one that
 * products still use.
 */
beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Tax Rate Guards Tester',
        'email' => 'tax-rate-guards-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);
});

function taxRateInUse(string $name = 'Standard', int $products = 2): TaxRate
{
    $rate = TaxRate::create(['name' => $name, 'rate' => 10]);

    for ($i = 1; $i <= $products; $i++) {
        Product::create(['name' => "{$name} product {$i}", 'code' => "{$name}-{$i}", 'active' => true, 'tax_rate_id' => $rate->id]);
    }

    return $rate;
}

function taxRateInUseMessage(int $count): string
{
    return ucfirst(__('laravel-crm::lang.cannot_delete_tax_rate_in_use', ['count' => $count]));
}

it('bounds the rate to 0–100', function (mixed $rate, bool $valid) {
    $page = livewire(CreateTaxRate::class)
        ->fillForm(['name' => 'Rate', 'rate' => $rate])
        ->call('create');

    $valid ? $page->assertHasNoFormErrors() : $page->assertHasFormErrors(['rate']);
})->with([
    'negative' => [-1, false],
    'zero' => [0, true],
    'hundred' => [100, true],
    'over a hundred' => [100.5, false],
]);

it('bounds the default tax rate setting to 0–100', function () {
    livewire(GeneralSettings::class)
        ->fillForm(['tax_rate' => 101])
        ->call('save')
        ->assertHasFormErrors(['tax_rate']);
});

it('refuses the table row delete for a rate in use', function () {
    $rate = taxRateInUse();

    livewire(ListTaxRates::class)
        ->callAction(TestAction::make(DeleteAction::getDefaultName())->table($rate))
        ->assertNotified(taxRateInUseMessage(2));

    expect(TaxRate::find($rate->id))->not->toBeNull();
});

it('still deletes an unused rate from the table', function () {
    $rate = TaxRate::create(['name' => 'Unused', 'rate' => 5]);

    livewire(ListTaxRates::class)
        ->callAction(TestAction::make(DeleteAction::getDefaultName())->table($rate));

    expect(TaxRate::find($rate->id))->toBeNull();
});

it('refuses the whole bulk delete if any selected rate is in use', function () {
    $unused = TaxRate::create(['name' => 'Unused', 'rate' => 5]);
    $used = taxRateInUse('Used', 1);

    livewire(ListTaxRates::class)
        ->selectTableRecords([$unused->id, $used->id])
        ->callAction(TestAction::make(DeleteBulkAction::getDefaultName())->table()->bulk())
        ->assertNotified(taxRateInUseMessage(1));

    expect(TaxRate::find($unused->id))->not->toBeNull()
        ->and(TaxRate::find($used->id))->not->toBeNull();
});

it('refuses the view page delete for a rate in use', function () {
    $rate = taxRateInUse();

    livewire(ViewTaxRate::class, ['record' => $rate->getRouteKey()])
        ->callAction(DeleteAction::getDefaultName())
        ->assertNotified(taxRateInUseMessage(2));

    expect(TaxRate::find($rate->id))->not->toBeNull();
});

it('refuses the edit page delete for a rate in use', function () {
    $rate = taxRateInUse();

    livewire(EditTaxRate::class, ['record' => $rate->getRouteKey()])
        ->callAction(DeleteAction::getDefaultName())
        ->assertNotified(taxRateInUseMessage(2));

    expect(TaxRate::find($rate->id))->not->toBeNull();
});
