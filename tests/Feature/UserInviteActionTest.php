<?php

use Filament\Facades\Filament;
use Filament\Schemas\Schema as FilamentSchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use VentureDrake\LaravelCrm\Mail\WelcomeImportedUser;
use VentureDrake\LaravelCrm\Models\Role;
use VentureDrake\LaravelCrm\Models\UserInvitation;
use VentureDrake\LaravelCrmFilament\Jobs\SendUserInvite;
use VentureDrake\LaravelCrmFilament\Notifications\UserInvitationNotification;
use VentureDrake\LaravelCrmFilament\Resources\Users\Pages\Concerns\HasUserInviteAction;
use VentureDrake\LaravelCrmFilament\Resources\Users\Pages\ListUsers;
use VentureDrake\LaravelCrmFilament\Support\InvitableEmail;
use VentureDrake\LaravelCrmFilament\Support\InvitationUrl;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\HostUser;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * The invite action, on core 2.4.0's UserInvitation lifecycle.
 *
 * The plugin used to create the host user up front with a random password;
 * 2.4.0 replaced that with an invitation the invitee redeems. These lock in
 * what that swap has to preserve: an invitation and no user, the role vetted
 * through Role::assignableBy() at both the dropdown and the validator, and a
 * server-side refusal that does not depend on the button having been hidden.
 *
 * The SendUserInvite job tests below stay — UserImporter still uses it.
 */
beforeEach(function () {
    RoleSeeder::seed();
});

/**
 * A user holding exactly the given CRM permissions and nothing else.
 */
function inviteActionUser(array $permissions): User
{
    $user = User::create([
        'name' => 'Invite Action Tester',
        'email' => 'invite-action-' . Str::random(8) . '@example.com',
        'password' => bcrypt('secret'),
    ]);

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

/**
 * `Password::createToken()` writes to the framework's reset-token table,
 * which the package's TestSchema does not ship. SQLite has transactional
 * DDL, so LazilyRefreshDatabase rolls this back.
 */
function createInvitePasswordResetTable(): void
{
    if (Schema::hasTable('password_reset_tokens')) {
        return;
    }

    Schema::create('password_reset_tokens', function (Blueprint $table) {
        $table->string('email')->primary();
        $table->string('token');
        $table->timestamp('created_at')->nullable();
    });
}

function createHostUsersTable(): void
{
    Schema::create('host_users', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password')->nullable();
        $table->boolean('crm_access')->default(true);
        $table->rememberToken();
        $table->timestamps();
    });
}

/**
 * The trait's invite logic, exercised outside a Filament page.
 */
function inviteActionHost(): object
{
    return new class
    {
        use HasUserInviteAction;

        /**
         * @param  array<string, mixed>  $data
         */
        public function invite(array $data): UserInvitation
        {
            return $this->inviteCrmUser($data);
        }

        /**
         * Run the action body itself, bypassing ->visible(). That bypass is
         * the point: it is what a hand-rolled Livewire call does.
         *
         * @param  array<string, mixed>  $data
         */
        public function runInviteAction(array $data): void
        {
            ($this->userInviteAction()->getActionFunction())($data);
        }
    };
}

// ----------------------------------------------------------------------------
// Wiring
// ----------------------------------------------------------------------------

it('wires the invite action into the Users list page header', function () {
    $instance = (new ReflectionClass(ListUsers::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ListUsers::class, 'getHeaderActions');
    $method->setAccessible(true);

    $names = array_map(fn ($action) => $action->getName(), $method->invoke($instance));

    expect($names)->toContain('invite');
    expect(class_uses_recursive(ListUsers::class))->toContain(HasUserInviteAction::class);
});

it('collects only email and role in the invite modal', function () {
    // The name field, the crm_access toggle and the ->unique() email rule all
    // went with inviteCrmUser()'s forceCreate: the invitee supplies their own
    // name and password at acceptance, and acceptance sets crm_access.
    $this->actingAs(inviteActionUser(['view crm users', 'create crm users']));

    /** @var ListUsers $instance */
    $instance = livewire(ListUsers::class)->instance();

    $action = $instance->getAction('invite');
    $names = array_map(
        fn ($component) => $component->getName(),
        $action->getSchema(FilamentSchema::make($instance))->getComponents(withHidden: true),
    );

    expect($names)->toBe(['email', 'role_id']);
});

// ----------------------------------------------------------------------------
// Happy path — an invitation, not a user
// ----------------------------------------------------------------------------

it('creates a pending invitation and mails it, without creating a user', function () {
    NotificationFacade::fake();

    $this->actingAs($inviter = inviteActionUser(['view crm users', 'create crm users']));

    $role = Role::findByName('Manager');

    livewire(ListUsers::class)
        ->callAction('invite', [
            'email' => 'Invitee.One@Example.COM',
            'role_id' => $role->id,
        ])
        ->assertHasNoActionErrors();

    $invitation = UserInvitation::where('email', 'invitee.one@example.com')->first();

    expect($invitation)->not->toBeNull()
        ->and($invitation->role_id)->toBe($role->id)
        ->and($invitation->invited_by)->toBe($inviter->id)
        ->and($invitation->last_sent_at)->not->toBeNull()
        // The observer mints both of these; a builder insert would not.
        ->and($invitation->external_id)->not->toBeEmpty()
        ->and(strlen((string) $invitation->code))->toBe(64)
        // Core's invitations are open-ended, and the plugin is single-tenant.
        ->and($invitation->expires_at)->toBeNull()
        ->and($invitation->team_id)->toBeNull();

    // No host user until the invitation is redeemed.
    expect(User::where('email', 'invitee.one@example.com')->exists())->toBeFalse();

    NotificationFacade::assertSentOnDemand(UserInvitationNotification::class);
});

it('rejects an email that already belongs to a user', function () {
    NotificationFacade::fake();

    User::create([
        'name' => 'Already Here',
        'email' => 'already.here@example.com',
        'password' => bcrypt('secret'),
    ]);

    $this->actingAs(inviteActionUser(['view crm users', 'create crm users']));

    livewire(ListUsers::class)
        ->callAction('invite', [
            'email' => 'already.here@example.com',
            'role_id' => Role::findByName('Manager')->id,
        ])
        ->assertHasActionErrors(['email']);

    expect(UserInvitation::where('email', 'already.here@example.com')->exists())->toBeFalse();
    NotificationFacade::assertNothingSent();
});

it('rejects a second invitation while one is still pending', function () {
    NotificationFacade::fake();

    $this->actingAs(inviteActionUser(['view crm users', 'create crm users']));

    UserInvitation::create([
        'email' => 'pending@example.com',
        'role_id' => Role::findByName('Manager')->id,
        'last_sent_at' => now(),
    ]);

    livewire(ListUsers::class)
        ->callAction('invite', [
            'email' => 'pending@example.com',
            'role_id' => Role::findByName('Manager')->id,
        ])
        ->assertHasActionErrors(['email']);

    expect(UserInvitation::where('email', 'pending@example.com')->count())->toBe(1);
});

it('rejects a role the caller is not entitled to hand out', function () {
    NotificationFacade::fake();

    // A non-Owner may not mint an Owner invitation. AssignableRole rejects the
    // payload before anything is written.
    $this->actingAs(inviteActionUser(['view crm users', 'create crm users']));

    livewire(ListUsers::class)
        ->callAction('invite', [
            'email' => 'escalation@example.com',
            'role_id' => Role::findByName('Owner')->id,
        ])
        ->assertHasActionErrors(['role_id']);

    expect(UserInvitation::where('email', 'escalation@example.com')->exists())->toBeFalse();
});

it('never offers Owner in the invite role dropdown to a non-Owner', function () {
    $this->actingAs(inviteActionUser(['view crm users', 'create crm users']));

    /** @var ListUsers $instance */
    $instance = livewire(ListUsers::class)->instance();

    $components = $instance->getAction('invite')
        ->getSchema(FilamentSchema::make($instance))
        ->getComponents(withHidden: true);

    $roleSelect = collect($components)->firstWhere(fn ($c) => $c->getName() === 'role_id');

    expect($roleSelect->getOptions())->not->toContain('Owner');
});

// ----------------------------------------------------------------------------
// Permission gate — `create crm users`
// ----------------------------------------------------------------------------

it('shows the invite action to a user holding create crm users', function () {
    $this->actingAs(inviteActionUser(['view crm users', 'create crm users']));

    livewire(ListUsers::class)->assertActionVisible('invite');
});

it('hides the invite action from a user without create crm users', function () {
    // The Employee role in core's matrix can view users but never create them.
    $user = User::create([
        'name' => 'Employee Tester',
        'email' => 'employee-' . Str::random(8) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $user->assignRole('Employee');

    $this->actingAs($user->fresh());

    expect($user->fresh()->hasPermissionTo('view crm users'))->toBeTrue();

    livewire(ListUsers::class)->assertActionHidden('invite');
});

it('refuses the invite server-side for a caller without create crm users', function () {
    // ->visible() hides a button; the abort_unless as the action closure's
    // first statement is what stops a hand-rolled Livewire call, which does
    // not go anywhere near ->visible().
    $user = User::create([
        'name' => 'Employee Caller',
        'email' => 'employee-caller-' . Str::random(8) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $user->assignRole('Employee');

    $this->actingAs($user->fresh());

    expect(fn () => inviteActionHost()->runInviteAction([
        'email' => 'sneaky@example.com',
        'role_id' => Role::findByName('Manager')->id,
    ]))->toThrow(HttpException::class);

    expect(UserInvitation::where('email', 'sneaky@example.com')->exists())->toBeFalse();
});

it('refuses the invite server-side under multi-tenancy, not just in the UI', function () {
    // The tenancy check used to live only in ->visible(), which hides a button
    // and stops nothing. A holder of `create crm users` could still mint an
    // untenanted invitation through a hand-rolled Livewire call — on a panel
    // whose whole posture is "we do not scope, so we do not pretend to".
    config()->set('laravel-crm.teams', true);

    $this->actingAs(inviteActionUser(['view crm users', 'create crm users']));

    livewire(ListUsers::class)->assertActionHidden('invite');

    expect(fn () => inviteActionHost()->runInviteAction([
        'email' => 'untenanted@example.com',
        'role_id' => Role::findByName('Manager')->id,
    ]))->toThrow(HttpException::class);

    expect(UserInvitation::where('email', 'untenanted@example.com')->exists())->toBeFalse();
});

it('gates on the create crm users permission name', function () {
    // Trait constants are not readable off the trait itself — read it off the
    // using class, which is where PHP composes it.
    expect(ListUsers::INVITE_PERMISSION)->toBe('create crm users');
});

// ----------------------------------------------------------------------------
// The invite job — host-agnostic user model
// ----------------------------------------------------------------------------

it('mails core\'s WelcomeImportedUser with a set-password link', function () {
    Mail::fake();
    createInvitePasswordResetTable();

    User::create([
        'name' => 'Mailed Invitee',
        'email' => 'mailed.invitee@example.com',
        'password' => bcrypt('secret'),
    ]);

    (new SendUserInvite('mailed.invitee@example.com'))->handle();

    Mail::assertSent(WelcomeImportedUser::class, function (WelcomeImportedUser $mail) {
        return $mail->recipientEmail === 'mailed.invitee@example.com'
            && $mail->name === 'Mailed Invitee'
            && str_contains($mail->setPasswordUrl, 'password/reset');
    });
});

it('sends nothing when no user matches the invited address', function () {
    Mail::fake();
    createInvitePasswordResetTable();

    (new SendUserInvite('nobody@example.com'))->handle();

    Mail::assertNothingSent();
});

it('resolves a host user model that is not App\\Models\\User', function () {
    Mail::fake();
    createInvitePasswordResetTable();
    createHostUsersTable();

    config()->set('auth.providers.users.model', HostUser::class);

    HostUser::forceCreate([
        'name' => 'Host Invitee',
        'email' => 'host.invitee@example.com',
        'password' => bcrypt('secret'),
    ]);

    expect(SendUserInvite::userModel())->toBe(HostUser::class);

    (new SendUserInvite('host.invitee@example.com'))->handle();

    Mail::assertSent(WelcomeImportedUser::class, fn (WelcomeImportedUser $mail) => $mail->name === 'Host Invitee'
        && $mail->recipientEmail === 'host.invitee@example.com');
});

it('vets the invite email against the host\'s configured model, not App\\Models\\User', function () {
    createHostUsersTable();

    config()->set('auth.providers.users.model', HostUser::class);

    HostUser::forceCreate([
        'name' => 'Host Occupant',
        'email' => 'host.occupant@example.com',
        'password' => bcrypt('secret'),
    ]);

    $failures = [];
    (new InvitableEmail)->validate('email', 'host.occupant@example.com', function ($message) use (&$failures) {
        $failures[] = $message;
    });

    expect($failures)->toHaveCount(1);
});

it('never hardcodes a user model in the invite job or action', function () {
    foreach ([SendUserInvite::class, HasUserInviteAction::class] as $class) {
        $source = (string) file_get_contents((new ReflectionClass($class))->getFileName());

        expect($source)->not->toMatch('/^use App\\\\/m');
        expect($source)->not->toContain('App\\Models\\User::class');
    }

    $jobSource = (string) file_get_contents((new ReflectionClass(SendUserInvite::class))->getFileName());
    expect($jobSource)->toContain("config('auth.providers.users.model')");
});

it('returns null rather than querying a bogus configured model', function () {
    config()->set('auth.providers.users.model', 'Not\\A\\Real\\Model');

    expect(SendUserInvite::userModel())->toBeNull();

    Mail::fake();
    (new SendUserInvite('anyone@example.com'))->handle();
    Mail::assertNothingSent();
});

// ----------------------------------------------------------------------------
// Set-password link resolution
//
// Base's `laravel-crm.password.reset` route lives in `auth-routes.php`, which
// LaravelCrmServiceProvider only loads while `laravel-crm.user_interface` is
// on — and the plugin's own installer asks operators to turn that off so the
// Filament panel can own /crm. The invite must not create an account nobody
// can ever sign into.
// ----------------------------------------------------------------------------

/**
 * Drop a named route from the router so the "user_interface=false" install can
 * be exercised without rebooting the whole application.
 */
function forgetInviteRoute(string $name): void
{
    $remaining = new RouteCollection;

    foreach (Route::getRoutes() as $route) {
        if ($route->getName() === $name) {
            continue;
        }

        $remaining->add($route);
    }

    app('router')->setRoutes($remaining);
}

it('builds the set-password link from base\'s route when the legacy UI is on', function () {
    expect(Route::has('laravel-crm.password.reset'))->toBeTrue();
    expect(SendUserInvite::canBuildSetPasswordUrl())->toBeTrue();
});

it('falls back to the panel\'s own reset route when base\'s route is gone', function () {
    Mail::fake();
    createInvitePasswordResetTable();

    forgetInviteRoute('laravel-crm.password.reset');

    // Stand in for a panel published with ->passwordReset(), which the
    // CrmPanelProvider stub now enables.
    Route::get('admin/password-reset/reset', fn () => '')
        ->name('filament.admin.auth.password-reset.reset');
    Route::getRoutes()->refreshNameLookups();
    Filament::getPanel('admin')->passwordReset();

    expect(SendUserInvite::canBuildSetPasswordUrl())->toBeTrue();

    User::create([
        'name' => 'Panel Invitee',
        'email' => 'panel.invitee@example.com',
        'password' => bcrypt('secret'),
    ]);

    (new SendUserInvite('panel.invitee@example.com'))->handle();

    Mail::assertSent(WelcomeImportedUser::class, fn (WelcomeImportedUser $mail) => str_contains($mail->setPasswordUrl, 'admin/password-reset/reset')
        && str_contains($mail->setPasswordUrl, 'signature='));
});

it('mails nothing rather than a dead link when no reset route exists at all', function () {
    Mail::fake();
    createInvitePasswordResetTable();

    forgetInviteRoute('laravel-crm.password.reset');

    expect(SendUserInvite::canBuildSetPasswordUrl())->toBeFalse();

    User::create([
        'name' => 'Stranded Invitee',
        'email' => 'stranded.invitee@example.com',
        'password' => bcrypt('secret'),
    ]);

    (new SendUserInvite('stranded.invitee@example.com'))->handle();

    Mail::assertNothingSent();
});

it('mints no invitation when there is nowhere to accept it', function () {
    // With neither the panel route nor base's, the invitee would get a mail
    // with a dead link. The pre-flight refuses before anything is committed.
    NotificationFacade::fake();

    forgetInviteRoute('laravel-crm.users.invitations.accept');
    forgetInviteRoute('filament.admin.invitations.accept');

    expect(InvitationUrl::exists())->toBeFalse();

    $this->actingAs(inviteActionUser(['view crm users', 'create crm users']));

    livewire(ListUsers::class)
        ->callAction('invite', [
            'email' => 'never.invited@example.com',
            'role_id' => Role::findByName('Manager')->id,
        ]);

    expect(UserInvitation::where('email', 'never.invited@example.com')->exists())->toBeFalse();
    NotificationFacade::assertNothingSent();
});

it('prefers the panel accept route over base\'s', function () {
    // Base's route only exists while laravel-crm.user_interface is on; the
    // panel's is the one that works in the plugin's recommended install.
    expect(InvitationUrl::panelRouteName())->toBe('filament.admin.invitations.accept');

    $invitation = UserInvitation::create([
        'email' => 'route.check@example.com',
        'last_sent_at' => now(),
    ]);

    expect(InvitationUrl::acceptUrl($invitation))
        ->toContain('admin/invitations/accept/' . $invitation->code);
});
