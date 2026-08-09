<?php

use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use VentureDrake\LaravelCrmFilament\Concerns\Imports\OrganizationImporter;
use VentureDrake\LaravelCrmFilament\Concerns\Imports\PersonImporter;
use VentureDrake\LaravelCrmFilament\Concerns\Imports\ProductImporter;
use VentureDrake\LaravelCrmFilament\Concerns\Imports\UserImporter;
use VentureDrake\LaravelCrmFilament\Concerns\ImportsCsv;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\Pages\ListOrganizations;
use VentureDrake\LaravelCrmFilament\Resources\People\Pages\ListPeople;
use VentureDrake\LaravelCrmFilament\Resources\Products\Pages\ListProducts;
use VentureDrake\LaravelCrmFilament\Resources\Users\Pages\ListUsers;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * CSV import had no authorization of any kind — no ->visible(), no
 * ->authorize(), no abort_unless — and ListUsers registered it
 * unconditionally. Any panel user could bulk-create accounts with
 * crm_access = 1.
 */
beforeEach(function () {
    RoleSeeder::seed();
});

function importUser(array $permissions = []): User
{
    $user = User::create([
        'name' => 'Import Tester',
        'email' => 'import-' . Str::random(8) . '@example.com',
        'password' => bcrypt('secret'),
    ]);

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

it('maps every importer to the create permission of its own resource', function () {
    expect((new UserImporter)->permission())->toBe('create crm users')
        ->and((new PersonImporter)->permission())->toBe('create crm people')
        ->and((new OrganizationImporter)->permission())->toBe('create crm organizations')
        ->and((new ProductImporter)->permission())->toBe('create crm products');
});

it('hides the import action from a user without the create permission', function ($page, $viewPermission) {
    $this->actingAs(importUser([$viewPermission]));

    livewire($page)->assertActionHidden('importCsv');
})->with([
    [ListUsers::class, 'view crm users'],
    [ListPeople::class, 'view crm people'],
    [ListOrganizations::class, 'view crm organizations'],
    [ListProducts::class, 'view crm products'],
]);

it('shows the import action to a user holding the create permission', function ($page, $permissions) {
    $this->actingAs(importUser($permissions));

    livewire($page)->assertActionVisible('importCsv');
})->with([
    [ListUsers::class, ['view crm users', 'create crm users']],
    [ListPeople::class, ['view crm people', 'create crm people']],
    [ListOrganizations::class, ['view crm organizations', 'create crm organizations']],
    [ListProducts::class, ['view crm products', 'create crm products']],
]);

it('aborts server-side even when the button was never rendered', function () {
    // Hiding a button is presentation. A Livewire action is callable by anyone
    // who can reach the page, so the abort_unless inside the closure is what
    // actually stops the import.
    $this->actingAs(importUser(['view crm users']));

    $action = ImportsCsv::action(UserImporter::class);

    expect(fn () => ($action->getActionFunction())(['file' => null]))
        ->toThrow(HttpException::class);
});

it('runs the import for a caller who does hold the permission', function () {
    $this->actingAs(importUser(['view crm users', 'create crm users']));

    $action = ImportsCsv::action(UserImporter::class);

    // Reaches runImport(), which reports "no file uploaded" rather than 403.
    ($action->getActionFunction())(['file' => null]);
})->throwsNoExceptions();

it('imports a user through the host\'s configured model and vets the role', function () {
    $this->actingAs(importUser(['view crm users', 'create crm users']));

    $importer = new UserImporter;

    // 'Owner' is not assignable by a non-Owner, so it is dropped rather than
    // failing the row — core's behaviour.
    expect($importer->importRow([
        'name' => 'Imported One',
        'email' => 'Imported.One@Example.COM',
        'role' => 'Owner',
    ]))->toBeTrue();

    $imported = User::where('email', 'imported.one@example.com')->first();

    expect($imported)->not->toBeNull()
        ->and((bool) $imported->crm_access)->toBeTrue()
        ->and($imported->roles)->toHaveCount(0);

    // An assignable role still lands.
    expect($importer->importRow([
        'name' => 'Imported Two',
        'email' => 'imported.two@example.com',
        'role' => 'Manager',
    ]))->toBeTrue();

    expect(User::where('email', 'imported.two@example.com')->first()->roles->pluck('name')->all())
        ->toBe(['Manager']);
});

it('no longer hardcodes App\\Models\\User or writes team pivots', function () {
    $source = (string) file_get_contents((new ReflectionClass(UserImporter::class))->getFileName());

    expect($source)->not->toMatch('/^use App\\\\/m')
        // The teams branch produced a half-tenanted user: a pivot row and a
        // current_team_id, with every subsequent panel query untenanted.
        ->and($source)->not->toContain('team_user')
        ->and($source)->not->toContain('current_team_id')
        // core's SendImportPasswordReset is App\Models\User-bound and silently
        // mails nothing otherwise.
        ->and($source)->not->toContain('SendImportPasswordReset::dispatch')
        ->and($source)->toContain('SendUserInvite::dispatch');
});
