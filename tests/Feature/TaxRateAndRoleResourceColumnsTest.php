<?php

use Filament\Tables\Columns\TextColumn;
use VentureDrake\LaravelCrmFilament\Resources\Roles\Pages\ListRoles;
use VentureDrake\LaravelCrmFilament\Resources\Roles\RoleResource;
use VentureDrake\LaravelCrmFilament\Resources\TaxRates\Pages\ListTaxRates;
use VentureDrake\LaravelCrmFilament\Resources\TaxRates\TaxRateResource;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'US006 Tester',
        'email' => 'us006-tester' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Owner');
    $this->actingAs($this->user);
});

it('TaxRateResource source declares products_count TextColumn with counts(products) and sales.products label', function () {
    $source = file_get_contents((new ReflectionClass(TaxRateResource::class))->getFileName());

    expect($source)
        ->toContain("TextColumn::make('products_count')")
        ->and($source)->toContain("->counts('products')")
        ->and($source)->toContain('labels.sales.products');
});

it('TaxRateResource table registers products_count as a TextColumn', function () {
    /** @var ListTaxRates $instance */
    $instance = livewire(ListTaxRates::class)->instance();

    $columns = $instance->getTable()->getColumns();

    expect($columns)->toHaveKey('products_count');
    expect($columns['products_count'])->toBeInstanceOf(TextColumn::class);
});

it('RoleResource form source has description TextInput between name and permissions', function () {
    // The guard_name form field was removed (Spatie's Role model defaults
    // guard_name to config('auth.defaults.guard') at construction), which
    // also removed the Grid::make(2) wrapper that previously paired name
    // with guard_name. name is now a full-width top-level TextInput.
    $source = file_get_contents((new ReflectionClass(RoleResource::class))->getFileName());

    expect($source)
        ->toContain("TextInput::make('description')")
        ->and($source)->toContain('->maxLength(255)');

    $namePos = strpos($source, "TextInput::make('name')");
    $descriptionPos = strpos($source, "TextInput::make('description')");
    $permissionsPos = strpos($source, "CheckboxList::make('permissions')");

    expect($namePos)->not->toBeFalse();
    expect($descriptionPos)->not->toBeFalse();
    expect($permissionsPos)->not->toBeFalse();
    expect($descriptionPos)->toBeGreaterThan($namePos);
    expect($descriptionPos)->toBeLessThan($permissionsPos);

    // Guard the removal explicitly so future regressions surface fast.
    expect($source)->not->toContain("TextInput::make('guard_name')");
});

it('RoleResource table source has description + users_count + permissions_count columns in order', function () {
    // The guard_name TABLE column was removed alongside the guard_name form
    // field (Spatie auto-fills guard_name from the default guard config, so
    // showing it in the table was pointless in a single-guard setup). A new
    // users_count column reads Role::users()->count() so admins can see how
    // many users hold each role at a glance.
    $source = file_get_contents((new ReflectionClass(RoleResource::class))->getFileName());

    expect($source)
        ->toContain("TextColumn::make('description')")
        ->and($source)->toContain('->limit(60)')
        ->and($source)->toContain("TextColumn::make('users_count')")
        ->and($source)->toContain("->counts('users')");

    $namePos = strpos($source, "TextColumn::make('name')");
    $descPos = strpos($source, "TextColumn::make('description')");
    $usersPos = strpos($source, "TextColumn::make('users_count')");
    $permsPos = strpos($source, "TextColumn::make('permissions_count')");

    expect($namePos)->not->toBeFalse();
    expect($descPos)->not->toBeFalse();
    expect($usersPos)->not->toBeFalse();
    expect($permsPos)->not->toBeFalse();
    expect($descPos)->toBeGreaterThan($namePos);
    expect($usersPos)->toBeGreaterThan($descPos);
    expect($permsPos)->toBeGreaterThan($usersPos);

    // Regression guard: guard_name column is gone.
    expect($source)->not->toContain("TextColumn::make('guard_name')");
});

it('RoleResource scopes its query to CRM roles instead of offering a crm_role filter', function () {
    // The TernaryFilter this replaces was redundant once the query is scoped,
    // and it carried no query() closure, so it SQL-errored on any install
    // without the column.
    $source = file_get_contents((new ReflectionClass(RoleResource::class))->getFileName());

    expect($source)->not->toContain("TernaryFilter::make('crm_role')");
    expect(RoleResource::getEloquentQuery()->toSql())->toContain('crm_role');
});

it('RoleResource table registers the description column', function () {
    /** @var ListRoles $instance */
    $instance = livewire(ListRoles::class)->instance();

    $table = $instance->getTable();

    $columns = $table->getColumns();
    expect($columns)->toHaveKey('description');
    expect($columns['description'])->toBeInstanceOf(TextColumn::class);

    expect($table->getFilters())->not->toHaveKey('crm_role');
});
