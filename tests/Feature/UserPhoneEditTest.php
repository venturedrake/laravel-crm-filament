<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrmFilament\Resources\Users\Pages\EditUser;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\UserWithPhones;

use function Pest\Livewire\livewire;

/**
 * The user form shares the phones repeater with people and organizations,
 * whose type is required with no blank option and whose number is checked
 * with core 2.7.0's PhoneNumber rule. A user's saved phones have to get
 * through both, as a person's do.
 */
beforeEach(function () {
    RoleSeeder::seed();

    config(['auth.providers.users.model' => UserWithPhones::class]);

    // The configured model, so Spatie finds the web guard for its roles.
    $admin = UserWithPhones::create([
        'name' => 'Phone Admin',
        'email' => 'phone-admin-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $admin->assignRole('Owner');

    $this->actingAs($admin->fresh());

    $this->user = UserWithPhones::create([
        'name' => 'Has A Phone',
        'email' => 'has-a-phone-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
});

it('saves a user whose phone has no type and a number the phone rule would refuse', function () {
    // Five digits, and no type: both passed the panel's user form before 2.7.0.
    $phone = $this->user->phones()->create([
        'external_id' => (string) Str::uuid(),
        'number' => '12345',
        'type' => null,
        'primary' => true,
    ]);

    livewire(EditUser::class, ['record' => $this->user->getKey()])
        ->fillForm(['name' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->user->fresh()->name)->toBe('Renamed')
        ->and($phone->fresh()->number)->toBe('12345')
        ->and($phone->fresh()->type)->toBe('mobile');
});

it('still checks a number that is not already saved on the user', function () {
    $phone = $this->user->phones()->create([
        'external_id' => (string) Str::uuid(),
        'number' => '+61 2 9999 9999',
        'type' => 'work',
        'primary' => true,
    ]);

    livewire(EditUser::class, ['record' => $this->user->getKey()])
        ->fillForm(['phones' => [['id' => $phone->id, 'number' => '12345', 'type' => 'work', 'primary' => true]]])
        ->call('save')
        ->assertHasFormErrors();

    expect($phone->fresh()->number)->toBe('+61 2 9999 9999');
});
