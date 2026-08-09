<?php

use Filament\Facades\Filament;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use VentureDrake\LaravelCrm\Models\Role;
use VentureDrake\LaravelCrm\Models\UserInvitation;
use VentureDrake\LaravelCrmFilament\Pages\AcceptInvitation;
use VentureDrake\LaravelCrmFilament\Support\InvitationUrl;
use VentureDrake\LaravelCrmFilament\Support\UserInvitationAcceptor;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * Acceptance with `laravel-crm.user_interface = false` — the plugin's own
 * recommended install mode, and the one core's acceptance flow cannot serve.
 *
 * Core gates `loadRoutesFrom(Http/routes.php)` on that flag and both accept
 * routes live in that file, so on a panel-only host
 * `route('laravel-crm.users.invitations.accept')` throws from inside a queued
 * notification: the admin sees "invitation sent" and the invitee gets nothing.
 * Every test here runs with the flag off so the panel route is doing the work.
 */
beforeEach(function () {
    RoleSeeder::seed();

    config()->set('laravel-crm.user_interface', false);

    // The flag is read at boot, so the already-registered core routes have to
    // be dropped by hand to reproduce a genuinely panel-only router.
    forgetCoreInvitationRoutes();
});

function forgetCoreInvitationRoutes(): void
{
    $remaining = new RouteCollection;

    foreach (Route::getRoutes() as $route) {
        if (str_starts_with((string) $route->getName(), 'laravel-crm.')) {
            continue;
        }

        $remaining->add($route);
    }

    app('router')->setRoutes($remaining);
}

function acceptInvitationFor(string $email, ?string $roleName = 'Manager', ?User $invitedBy = null): UserInvitation
{
    return UserInvitation::create([
        'email' => $email,
        'role_id' => $roleName ? Role::findByName($roleName)->id : null,
        'invited_by' => $invitedBy?->id,
        'last_sent_at' => now(),
    ]);
}

it('still builds a working accept link with the base UI switched off', function () {
    expect(Route::has(InvitationUrl::CORE_ROUTE))->toBeFalse();

    $invitation = acceptInvitationFor('panel.only@example.com');

    expect(InvitationUrl::acceptUrl($invitation))
        ->toContain('admin/invitations/accept/' . $invitation->code);
});

it('creates the host user, grants CRM access and applies the role', function () {
    $invitation = acceptInvitationFor('brand.new@example.com');

    livewire(AcceptInvitation::class, ['code' => $invitation->code])
        ->fillForm([
            'name' => 'Brand New',
            'password' => 'correct-horse',
            'password_confirmation' => 'correct-horse',
        ])
        ->call('accept')
        // Asserted explicitly, because the DB assertions below pass either way.
        // accept() used to declare `: ?RedirectResponse` while Livewire rebinds
        // `redirect()` to a Redirector whose to() returns $this — a TypeError
        // Livewire converts to a bare 419. Every invitee was created, logged in
        // and then shown "page expired"; reloading 404'd on the now-accepted
        // invitation. Nothing but a redirect assertion catches that.
        ->assertRedirect();

    $user = User::where('email', 'brand.new@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Brand New')
        ->and((bool) $user->crm_access)->toBeTrue()
        ->and($user->roles->pluck('name')->all())->toBe(['Manager'])
        ->and(auth()->id())->toBe($user->id);

    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});

it('rejects a password shorter than 8 characters', function () {
    $invitation = acceptInvitationFor('weak@example.com');

    livewire(AcceptInvitation::class, ['code' => $invitation->code])
        ->fillForm([
            'name' => 'Weak',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
        ->call('accept')
        ->assertHasFormErrors(['password']);

    expect(User::where('email', 'weak@example.com')->exists())->toBeFalse();
});

/**
 * The mismatch case needs its own test, and the assertion has to be on the
 * *form* error rather than merely "no user was created".
 *
 * Both fixtures in the old combined test used matching passwords, so the
 * confirmation path was never exercised. It was broken: the only `confirmed`
 * rule lived in UserInvitationAcceptor's own validator, which keys its error on
 * `password` while the Filament field's state path is `data.password`. Nothing
 * bound, nothing rendered — the invitee pressed the button and the page sat
 * there.
 */
it('reports a mismatched password confirmation on the field', function () {
    $invitation = acceptInvitationFor('mismatch@example.com');

    livewire(AcceptInvitation::class, ['code' => $invitation->code])
        ->fillForm([
            'name' => 'Mismatch',
            'password' => 'correct-horse',
            'password_confirmation' => 'different-horse',
        ])
        ->call('accept')
        ->assertHasFormErrors(['password']);

    expect(User::where('email', 'mismatch@example.com')->exists())->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

it('grants an existing host user CRM access without touching their password', function () {
    $existing = User::create([
        'name' => 'Already Here',
        'email' => 'already@example.com',
        'password' => bcrypt('their-own-password'),
        'crm_access' => false,
    ]);

    $invitation = acceptInvitationFor('already@example.com');

    $this->actingAs($existing);

    // mount() runs the acceptance for a logged-in matching user.
    livewire(AcceptInvitation::class, ['code' => $invitation->code]);

    $existing->refresh();

    expect((bool) $existing->crm_access)->toBeTrue()
        ->and($existing->roles->pluck('name')->all())->toBe(['Manager'])
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();
});

it('swaps out an existing CRM role rather than stacking a second one', function () {
    $existing = User::create([
        'name' => 'Role Swap',
        'email' => 'swap@example.com',
        'password' => bcrypt('secret'),
    ]);
    $existing->assignRole('Employee');

    $invitation = acceptInvitationFor('swap@example.com');

    $this->actingAs($existing->fresh());

    livewire(AcceptInvitation::class, ['code' => $invitation->code]);

    expect($existing->fresh()->roles->pluck('name')->all())->toBe(['Manager']);
});

it('matches the invitation email case-insensitively', function () {
    $existing = User::create([
        'name' => 'Mixed Case',
        'email' => 'mixed.case@example.com',
        'password' => bcrypt('secret'),
    ]);

    $invitation = acceptInvitationFor('Mixed.Case@Example.COM');

    expect(UserInvitationAcceptor::findHostUser($invitation->email)?->id)->toBe($existing->id);
});

it('sends a signed-out existing user to the panel login carrying the code', function () {
    // Stand in for a panel published with ->login(); core would have sent them
    // to route('laravel-crm.login'), which is gated off here.
    Route::get('admin/login', fn () => '')->name('filament.admin.auth.login');
    Route::getRoutes()->refreshNameLookups();
    Filament::getPanel('admin')->login();

    User::create([
        'name' => 'Signed Out',
        'email' => 'signed.out@example.com',
        'password' => bcrypt('secret'),
    ]);

    $invitation = acceptInvitationFor('signed.out@example.com');

    $result = UserInvitationAcceptor::inspect($invitation);

    expect($result['outcome'])->toBe(UserInvitationAcceptor::OUTCOME_LOGIN_REQUIRED)
        ->and($result['url'])->toContain('admin/login')
        ->and($result['url'])->toContain('invitation=' . $invitation->code);
});

it('falls back to the app root when the panel has no login page', function () {
    User::create([
        'name' => 'No Login Page',
        'email' => 'no.login@example.com',
        'password' => bcrypt('secret'),
    ]);

    $invitation = acceptInvitationFor('no.login@example.com');

    // Never a RouteNotFoundException — that is the failure mode this whole
    // phase exists to remove.
    expect(UserInvitationAcceptor::loginUrlFor($invitation))
        ->toContain('invitation=' . $invitation->code);
});

it('403s when a different user is signed in', function () {
    User::create([
        'name' => 'Intended',
        'email' => 'intended@example.com',
        'password' => bcrypt('secret'),
    ]);

    $other = User::create([
        'name' => 'Somebody Else',
        'email' => 'somebody.else@example.com',
        'password' => bcrypt('secret'),
    ]);

    $invitation = acceptInvitationFor('intended@example.com');

    $this->actingAs($other);

    // Asserted over HTTP rather than through the Livewire test helper, so the
    // route registration (outside the panel's auth middleware) is exercised too.
    $this->withoutExceptionHandling();

    expect(fn () => $this->get(route('filament.admin.invitations.accept', ['code' => $invitation->code])))
        ->toThrow(AccessDeniedHttpException::class);

    expect($invitation->fresh()->accepted_at)->toBeNull();
});

it('404s on an unknown, revoked, accepted or expired code', function () {
    expect(UserInvitationAcceptor::find('nope'))->toBeNull();
    expect(UserInvitationAcceptor::find(null))->toBeNull();

    $revoked = acceptInvitationFor('revoked-' . Str::random(4) . '@example.com');
    $revoked->delete();
    expect(UserInvitationAcceptor::find($revoked->code))->toBeNull();

    $accepted = acceptInvitationFor('accepted-' . Str::random(4) . '@example.com');
    $accepted->forceFill(['accepted_at' => now()])->save();
    expect(UserInvitationAcceptor::find($accepted->code))->toBeNull();

    $expired = acceptInvitationFor('expired-' . Str::random(4) . '@example.com');
    $expired->forceFill(['expires_at' => now()->subMinute()])->save();
    expect(UserInvitationAcceptor::find($expired->code))->toBeNull();

    $this->withoutExceptionHandling();

    expect(fn () => $this->get(route('filament.admin.invitations.accept', ['code' => $revoked->code])))
        ->toThrow(NotFoundHttpException::class);
});

it('re-resolves the invitation on submit rather than trusting the wire payload', function () {
    // Every public Livewire property is client-writable, so the invitation is
    // looked up again from $code on the way in.
    $invitation = acceptInvitationFor('revoked.mid.flow@example.com');

    $component = livewire(AcceptInvitation::class, ['code' => $invitation->code])
        ->fillForm([
            'name' => 'Too Late',
            'password' => 'correct-horse',
            'password_confirmation' => 'correct-horse',
        ]);

    // An admin revokes it while the form is open.
    $invitation->delete();

    try {
        $component->call('accept');
    } catch (NotFoundHttpException) {
        // expected
    }

    expect(User::where('email', 'revoked.mid.flow@example.com')->exists())->toBeFalse();
});
