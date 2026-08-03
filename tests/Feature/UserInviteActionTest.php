<?php

use Filament\Facades\Filament;
use Filament\Schemas\Schema as FilamentSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use VentureDrake\LaravelCrm\Mail\WelcomeImportedUser;
use VentureDrake\LaravelCrmFilament\Jobs\SendUserInvite;
use VentureDrake\LaravelCrmFilament\Resources\Users\Pages\Concerns\HasUserInviteAction;
use VentureDrake\LaravelCrmFilament\Resources\Users\Pages\ListUsers;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\HostUser;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * US-007 — the invite flow core CRM exposes at `laravel-crm.users.invite`
 * had no Filament equivalent at all. These lock in the three things that
 * matter: the invite really creates + roles + queues, only holders of
 * `create crm users` can see it, and the mail job resolves the host's
 * configured user model instead of a hardcoded `App\Models\User`.
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
        public function invite(array $data): Model
        {
            return $this->inviteCrmUser($data);
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

it('collects name, email, role and crm_access in the invite modal', function () {
    $this->actingAs(inviteActionUser(['view crm users', 'create crm users']));

    /** @var ListUsers $instance */
    $instance = livewire(ListUsers::class)->instance();

    $action = $instance->getAction('invite');
    $names = array_map(
        fn ($component) => $component->getName(),
        $action->getSchema(FilamentSchema::make($instance))->getComponents(withHidden: true),
    );

    expect($names)->toBe(['name', 'email', 'role_id', 'crm_access']);
});

// ----------------------------------------------------------------------------
// Happy path
// ----------------------------------------------------------------------------

it('creates the user with a random password, assigns the role and dispatches the invite', function () {
    Bus::fake();

    $this->actingAs(inviteActionUser(['view crm users', 'create crm users']));

    $role = Role::findByName('Manager');

    livewire(ListUsers::class)
        ->callAction('invite', [
            'name' => 'Invitee One',
            'email' => 'Invitee.One@Example.COM',
            'role_id' => $role->id,
            'crm_access' => true,
        ])
        ->assertHasNoActionErrors();

    $invited = User::where('email', 'invitee.one@example.com')->first();

    expect($invited)->not->toBeNull()
        ->and($invited->name)->toBe('Invitee One')
        ->and((bool) $invited->crm_access)->toBeTrue()
        ->and($invited->roles->pluck('name')->all())->toBe(['Manager']);

    Bus::assertDispatched(
        SendUserInvite::class,
        fn (SendUserInvite $job) => $job->email === 'invitee.one@example.com',
    );
});

it('hashes a random password rather than storing anything guessable', function () {
    Bus::fake();

    $host = inviteActionHost();

    $first = $host->invite(['name' => 'Random One', 'email' => 'random.one@example.com', 'crm_access' => true]);
    $second = $host->invite(['name' => 'Random Two', 'email' => 'random.two@example.com', 'crm_access' => true]);

    expect($first->password)->not->toBeEmpty()
        ->and($first->password)->not->toBe($second->password)
        ->and(Hash::isHashed($first->password))->toBeTrue()
        ->and(Str::startsWith($first->password, '$'))->toBeTrue();
});

it('stores crm_access off when the toggle is cleared', function () {
    Bus::fake();

    $invited = inviteActionHost()->invite([
        'name' => 'No Panel',
        'email' => 'no.panel@example.com',
        'crm_access' => false,
    ]);

    expect((bool) $invited->fresh()->crm_access)->toBeFalse();
});

it('invites without a role when none is chosen', function () {
    Bus::fake();

    $invited = inviteActionHost()->invite([
        'name' => 'Roleless',
        'email' => 'roleless@example.com',
        'role_id' => null,
        'crm_access' => true,
    ]);

    expect($invited->roles)->toHaveCount(0);

    Bus::assertDispatched(SendUserInvite::class);
});

it('rejects an email that already belongs to a user', function () {
    Bus::fake();

    User::create([
        'name' => 'Already Here',
        'email' => 'already.here@example.com',
        'password' => bcrypt('secret'),
    ]);

    $this->actingAs(inviteActionUser(['view crm users', 'create crm users']));

    livewire(ListUsers::class)
        ->callAction('invite', [
            'name' => 'Duplicate',
            'email' => 'already.here@example.com',
            'crm_access' => true,
        ])
        ->assertHasActionErrors(['email']);

    Bus::assertNotDispatched(SendUserInvite::class);
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

it('creates the invited user through the host\'s configured model', function () {
    Bus::fake();
    createHostUsersTable();

    config()->set('auth.providers.users.model', HostUser::class);

    $invited = inviteActionHost()->invite([
        'name' => 'Host Created',
        'email' => 'host.created@example.com',
        'crm_access' => true,
    ]);

    expect($invited)->toBeInstanceOf(HostUser::class)
        ->and(HostUser::where('email', 'host.created@example.com')->exists())->toBeTrue()
        // The record must NOT have landed in the package's default users table.
        ->and(User::where('email', 'host.created@example.com')->exists())->toBeFalse();
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

it('refuses to create the account when no set-password link can be built', function () {
    Bus::fake();

    forgetInviteRoute('laravel-crm.password.reset');

    $this->actingAs(inviteActionUser(['view crm users', 'create crm users']));

    livewire(ListUsers::class)
        ->callAction('invite', [
            'name' => 'Never Created',
            'email' => 'never.created@example.com',
            'crm_access' => true,
        ]);

    expect(User::where('email', 'never.created@example.com')->exists())->toBeFalse();
    Bus::assertNotDispatched(SendUserInvite::class);
});
