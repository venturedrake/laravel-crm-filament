<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Role;
use VentureDrake\LaravelCrm\Models\UserInvitation;
use VentureDrake\LaravelCrmFilament\Pages\AcceptInvitation;
use VentureDrake\LaravelCrmFilament\Support\UserInvitationAcceptor;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * Named after what it prevents: an invitation redeeming into an Owner when
 * nobody entitled ever handed one out.
 *
 * The invite action only offers Owner to an existing Owner, but that guard is
 * one layer. An invitation minted before the guard existed, or written by any
 * other path (a seeder, a console command, a direct insert), must not be
 * redeemable into an Owner either — and the entitlement that matters at
 * redemption time is the *inviter's*, checked against invited_by.
 */
beforeEach(function () {
    RoleSeeder::seed();
});

function escalationUser(string $label, ?string $roleName = null): User
{
    $user = User::create([
        'name' => $label,
        'email' => Str::slug($label) . '-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);

    if ($roleName) {
        $user->assignRole($roleName);
    }

    return $user->fresh();
}

it('drops an Owner role when the inviter is not an Owner', function () {
    $inviter = escalationUser('Manager Inviter', 'Manager');

    $invitation = UserInvitation::create([
        'email' => 'escalated@example.com',
        'role_id' => Role::findByName('Owner')->id,
        'invited_by' => $inviter->id,
        'last_sent_at' => now(),
    ]);

    expect(UserInvitationAcceptor::invitationRole($invitation))->toBeNull();
});

it('drops an Owner role when invited_by cannot be verified at all', function () {
    // Fails closed: the invitee still gets CRM access and an Owner can set
    // their role afterwards. Silently minting an Owner is not recoverable.
    $invitation = UserInvitation::create([
        'email' => 'orphaned@example.com',
        'role_id' => Role::findByName('Owner')->id,
        'invited_by' => null,
        'last_sent_at' => now(),
    ]);

    expect(UserInvitationAcceptor::invitationRole($invitation))->toBeNull();

    $deleted = escalationUser('Since Deleted', 'Owner');
    $deletedId = $deleted->id;
    $deleted->forceDelete();

    $second = UserInvitation::create([
        'email' => 'orphaned2@example.com',
        'role_id' => Role::findByName('Owner')->id,
        'invited_by' => $deletedId,
        'last_sent_at' => now(),
    ]);

    expect(UserInvitationAcceptor::invitationRole($second))->toBeNull();
});

it('honours an Owner role when the inviter really is an Owner', function () {
    $owner = escalationUser('Real Owner', 'Owner');

    $invitation = UserInvitation::create([
        'email' => 'legitimate@example.com',
        'role_id' => Role::findByName('Owner')->id,
        'invited_by' => $owner->id,
        'last_sent_at' => now(),
    ]);

    expect(UserInvitationAcceptor::invitationRole($invitation)?->name)->toBe('Owner');
});

it('creates the redeemed user without the Owner role end to end', function () {
    config()->set('laravel-crm.user_interface', false);

    $inviter = escalationUser('Sneaky Manager', 'Manager');

    $invitation = UserInvitation::create([
        'email' => 'not.an.owner@example.com',
        'role_id' => Role::findByName('Owner')->id,
        'invited_by' => $inviter->id,
        'last_sent_at' => now(),
    ]);

    livewire(AcceptInvitation::class, ['code' => $invitation->code])
        ->fillForm([
            'name' => 'Not An Owner',
            'password' => 'correct-horse',
            'password_confirmation' => 'correct-horse',
        ])
        ->call('accept');

    $user = User::where('email', 'not.an.owner@example.com')->first();

    expect($user)->not->toBeNull()
        // CRM access is still granted — the invitation was real, only the role
        // was not defensible.
        ->and((bool) $user->crm_access)->toBeTrue()
        ->and($user->roles)->toHaveCount(0);
});

it('leaves an existing user\'s role alone rather than escalating it', function () {
    config()->set('laravel-crm.user_interface', false);

    $inviter = escalationUser('Another Manager', 'Manager');
    $existing = escalationUser('Existing Employee', 'Employee');

    $invitation = UserInvitation::create([
        'email' => $existing->email,
        'role_id' => Role::findByName('Owner')->id,
        'invited_by' => $inviter->id,
        'last_sent_at' => now(),
    ]);

    $this->actingAs($existing);

    livewire(AcceptInvitation::class, ['code' => $invitation->code]);

    // invitationRole() returned null, so the removeRole/assignRole pair never
    // ran and the user keeps what they had.
    expect($existing->fresh()->roles->pluck('name')->all())->toBe(['Employee'])
        ->and((bool) $existing->fresh()->crm_access)->toBeTrue();
});
