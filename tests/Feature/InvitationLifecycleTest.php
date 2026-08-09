<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Role;
use VentureDrake\LaravelCrm\Models\UserInvitation;
use VentureDrake\LaravelCrmFilament\Notifications\UserInvitationNotification;
use VentureDrake\LaravelCrmFilament\Resources\UserInvitations\Pages\ListUserInvitations;
use VentureDrake\LaravelCrmFilament\Resources\UserInvitations\UserInvitationResource;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * The pending-invitations list: what it shows, what it does, and who may.
 */
beforeEach(function () {
    RoleSeeder::seed();
});

function invitationManager(array $permissions = ['view crm users', 'create crm users']): User
{
    $user = User::create([
        'name' => 'Invitation Manager',
        'email' => 'invitations-' . Str::random(8) . '@example.com',
        'password' => bcrypt('secret'),
    ]);

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

function pendingInvitation(array $attributes = []): UserInvitation
{
    return UserInvitation::create(array_merge([
        'email' => 'invitee-' . Str::random(6) . '@example.com',
        'role_id' => Role::findByName('Manager')->id,
        'last_sent_at' => now()->subDay(),
    ], $attributes));
}

it('registers an index page and nothing else', function () {
    // The route key is `code`, the 64-character secret. A View or Edit page
    // would route-bind the record and put that secret in a URL — and from
    // there into history, referrers and access logs. See the class docblock.
    expect(array_keys(UserInvitationResource::getPages()))->toBe(['index']);
    expect(UserInvitationResource::shouldRegisterNavigation())->toBeFalse();
});

it('lists only invitations that are still redeemable', function () {
    $this->actingAs(invitationManager());

    $pending = pendingInvitation(['email' => 'pending@example.com']);
    pendingInvitation(['email' => 'accepted@example.com', 'accepted_at' => now()]);
    pendingInvitation(['email' => 'expired@example.com', 'expires_at' => now()->subHour()]);

    livewire(ListUserInvitations::class)
        ->assertCanSeeTableRecords([$pending])
        ->assertCountTableRecords(1);
});

it('resends without minting a new code and stamps last_sent_at', function () {
    NotificationFacade::fake();

    $this->actingAs(invitationManager());

    $invitation = pendingInvitation();
    $code = $invitation->code;
    $lastSent = $invitation->last_sent_at->copy();

    livewire(ListUserInvitations::class)
        ->callAction(TestAction::make('resend')->table($invitation));

    $invitation->refresh();

    // Rotating the code on resend would lock out an invitee who kept the
    // first mail — core does not, and neither does this.
    expect($invitation->code)->toBe($code)
        ->and($invitation->last_sent_at->greaterThan($lastSent))->toBeTrue();

    NotificationFacade::assertSentOnDemand(UserInvitationNotification::class);
});

/**
 * Both halves of the "no acceptance route" story, which used to crash rather
 * than degrade.
 *
 * toMail() returned null and expected MailChannel to skip the send — but that
 * guard only fires when the notifiable's mail route is falsy, and these are
 * always sent via `Notification::route('mail', $email)`. So it fell through to
 * `$message->view` on null. Withdrawing the channel in via() is what actually
 * stops it. The resend action then needs the same pre-flight the invite action
 * has, or the admin gets a green toast and a stamped last_sent_at for mail
 * nobody sent.
 */
it('withdraws the mail channel when no acceptance route is registered', function () {
    $invitation = pendingInvitation();

    // Neither the panel route nor core's exists under this router.
    forgetEveryInvitationRoute();

    $notification = new UserInvitationNotification($invitation);

    expect($notification->via(NotificationFacade::route('mail', $invitation->email)))->toBe([]);
});

it('refuses to resend, rather than crash, with no acceptance route', function () {
    NotificationFacade::fake();

    $this->actingAs(invitationManager());

    $invitation = pendingInvitation();
    $lastSent = $invitation->last_sent_at->copy();

    forgetEveryInvitationRoute();

    livewire(ListUserInvitations::class)
        ->callAction(TestAction::make('resend')->table($invitation));

    NotificationFacade::assertNothingSent();

    // Not stamped: nothing was sent, so nothing should claim otherwise.
    expect($invitation->fresh()->last_sent_at->equalTo($lastSent))->toBeTrue();
});

function forgetEveryInvitationRoute(): void
{
    $remaining = new RouteCollection;

    foreach (Route::getRoutes() as $route) {
        $name = (string) $route->getName();

        if (str_contains($name, 'invitations.accept')) {
            continue;
        }

        $remaining->add($route);
    }

    app('router')->setRoutes($remaining);
}

it('revokes by soft-deleting, so the link stops working', function () {
    $this->actingAs(invitationManager());

    $invitation = pendingInvitation();

    livewire(ListUserInvitations::class)
        ->callAction(TestAction::make('delete')->table($invitation));

    expect(UserInvitation::query()->whereKey($invitation->id)->exists())->toBeFalse()
        ->and(UserInvitation::withTrashed()->whereKey($invitation->id)->exists())->toBeTrue();
});

it('denies the whole surface to a user who cannot create users', function () {
    // Core ships no UserInvitationPolicy, and Filament allows when no policy
    // resolves — so without these overrides the list, the resend and the
    // revoke were open to every panel user.
    $employee = User::create([
        'name' => 'Employee',
        'email' => 'employee-' . Str::random(8) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $employee->assignRole('Employee');

    $this->actingAs($employee->fresh());

    expect(UserInvitationResource::canViewAny())->toBeFalse();

    $invitation = pendingInvitation();

    expect(UserInvitationResource::canDelete($invitation))->toBeFalse();
});

it('never offers a create page — invitations come from the invite action', function () {
    $this->actingAs(invitationManager());

    expect(UserInvitationResource::canCreate())->toBeFalse();
});

it('badges the pending count on the cross-link back from Users', function () {
    $this->actingAs(invitationManager());

    expect(UserInvitationResource::pendingBadge())->toBeNull();

    pendingInvitation();
    pendingInvitation();

    expect(UserInvitationResource::pendingBadge())->toBe('2');
});
