<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Contact;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrmFilament\RelationManagers\FeatureCommentsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\RelatedOrganizationsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\RelatedPeopleRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\Pages\ViewOrganization;
use VentureDrake\LaravelCrmFilament\Resources\People\Pages\ViewPerson;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * Filament v4 actions carry NO implicit policy authorization — see
 * Filament\Actions\Concerns\CanBeAuthorized, whose `$authorization` defaults to
 * null meaning "allowed for everyone". A `->authorize(fn () => true)` escape
 * hatch is therefore not merely redundant, it documents an action nobody ever
 * gated. These tests lock in that none remain and that the replacements
 * actually deny.
 */
beforeEach(function () {
    RoleSeeder::seed();
});

function relationManagerSources(): array
{
    return glob(__DIR__ . '/../../src/RelationManagers/*.php') ?: [];
}

/**
 * A user holding exactly the given CRM permissions and nothing else.
 */
function rmAuthUser(array $permissions): User
{
    $user = User::create([
        'name' => 'RM Auth Tester',
        'email' => 'rm-auth-' . Str::random(8) . '@example.com',
        'password' => bcrypt('secret'),
    ]);

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

// ----------------------------------------------------------------------------
// (a) No blanket escape hatches remain
// ----------------------------------------------------------------------------

it('leaves no blanket authorize() escape hatch under src/RelationManagers', function () {
    $offenders = [];

    foreach (relationManagerSources() as $file) {
        if (preg_match('/->authorize\(\s*fn\s*\([^)]*\)\s*(:\s*bool\s*)?=>\s*true\s*[,)]/', (string) file_get_contents($file))) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([]);
});

it('leaves no blanket authorize() escape hatch anywhere under src/', function () {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__ . '/../../src'),
    );

    $offenders = [];

    foreach ($iterator as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        if (preg_match('/->authorize\(\s*fn\s*\([^)]*\)\s*(:\s*bool\s*)?=>\s*true\s*[,)]/', (string) file_get_contents($file->getPathname()))) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});

// ----------------------------------------------------------------------------
// (a) Related* managers defer to the base ContactPolicy
// ----------------------------------------------------------------------------

dataset('relatedContactManagers', [
    'RelatedPeopleRelationManager' => [
        RelatedPeopleRelationManager::class,
        ViewPerson::class,
    ],
    'RelatedOrganizationsRelationManager' => [
        RelatedOrganizationsRelationManager::class,
        ViewOrganization::class,
    ],
]);

function rmOwnerPerson(): Person
{
    return Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => 'Owner',
        'last_name' => 'Record',
    ]);
}

it('resolves the filtered morphMany to Contact, so the base ContactPolicy governs it', function (string $rm): void {
    $instance = (new ReflectionClass($rm))->newInstanceWithoutConstructor();
    $instance->ownerRecord = rmOwnerPerson();

    expect($instance->getRelatedContactModel())->toBe(Contact::class);
})->with('relatedContactManagers');

it('hides the link action from a user without create crm contacts', function (string $rm, string $page): void {
    $this->actingAs(rmAuthUser(['view crm contacts']));

    livewire($rm, [
        'ownerRecord' => rmOwnerPerson(),
        'pageClass' => $page,
    ])->assertActionHidden(TestAction::make('create')->table());
})->with('relatedContactManagers');

it('shows the link action to a user with create crm contacts', function (string $rm, string $page): void {
    $this->actingAs(rmAuthUser(['view crm contacts', 'create crm contacts']));

    livewire($rm, [
        'ownerRecord' => rmOwnerPerson(),
        'pageClass' => $page,
    ])->assertActionVisible(TestAction::make('create')->table());
})->with('relatedContactManagers');

it('hides the unlink action from a user without delete crm contacts', function (): void {
    $this->actingAs(rmAuthUser(['view crm contacts', 'create crm contacts']));

    $owner = rmOwnerPerson();
    $related = Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => 'Related',
        'last_name' => 'Person',
    ]);

    $contact = $owner->contacts()->create([
        'entityable_type' => $related->getMorphClass(),
        'entityable_id' => $related->id,
    ]);

    livewire(RelatedPeopleRelationManager::class, [
        'ownerRecord' => $owner->fresh(),
        'pageClass' => ViewPerson::class,
    ])->assertActionHidden(TestAction::make('delete')->table($contact));
});

it('shows the unlink action to a user with delete crm contacts', function (): void {
    $this->actingAs(rmAuthUser(['view crm contacts', 'delete crm contacts']));

    $owner = rmOwnerPerson();
    $related = Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => 'Related',
        'last_name' => 'Person',
    ]);

    $contact = $owner->contacts()->create([
        'entityable_type' => $related->getMorphClass(),
        'entityable_id' => $related->id,
    ]);

    livewire(RelatedPeopleRelationManager::class, [
        'ownerRecord' => $owner->fresh(),
        'pageClass' => ViewPerson::class,
    ])->assertActionVisible(TestAction::make('delete')->table($contact));
});

it('hides the organization link action from a user without create crm contacts', function (): void {
    $this->actingAs(rmAuthUser(['view crm contacts']));

    $owner = Organization::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Owner Org',
    ]);

    livewire(RelatedOrganizationsRelationManager::class, [
        'ownerRecord' => $owner,
        'pageClass' => ViewOrganization::class,
    ])->assertActionHidden(TestAction::make('create')->table());
});

// ----------------------------------------------------------------------------
// (a) FeatureComments — explicit permissions, author-or-admin scoping
//
// Core CRM 2.1.1 ships no Feature/FeatureComment model, so these exercise the
// authorization methods directly rather than through a mounted manager.
// ----------------------------------------------------------------------------

function featureCommentsRm(): FeatureCommentsRelationManager
{
    return (new ReflectionClass(FeatureCommentsRelationManager::class))->newInstanceWithoutConstructor();
}

function featureCommentStandIn(?int $authorId): Model
{
    $record = new class extends Model
    {
        protected $guarded = [];
    };

    $record->user_created_id = $authorId;

    return $record;
}

it('denies commenting to a user without edit crm features', function () {
    $this->actingAs(rmAuthUser(['view crm features']));

    expect(featureCommentsRm()->canCreateFeatureComment())->toBeFalse();
});

it('allows commenting for a user with edit crm features', function () {
    $this->actingAs(rmAuthUser(['edit crm features']));

    expect(featureCommentsRm()->canCreateFeatureComment())->toBeTrue();
});

it('denies commenting to a guest', function () {
    expect(featureCommentsRm()->canCreateFeatureComment())->toBeFalse();
});

it('lets a permitted user edit their own comment but not someone else\'s', function () {
    $author = rmAuthUser(['edit crm features']);
    $this->actingAs($author);

    $rm = featureCommentsRm();

    expect($rm->canEditFeatureComment(featureCommentStandIn($author->id)))->toBeTrue();
    expect($rm->canEditFeatureComment(featureCommentStandIn($author->id + 1000)))->toBeFalse();
});

it('lets an admin edit and delete another user\'s comment', function () {
    $admin = User::create([
        'name' => 'Comment Admin',
        'email' => 'comment-admin-' . Str::random(8) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $admin->assignRole('Admin');
    $this->actingAs($admin->fresh());

    $rm = featureCommentsRm();
    $someoneElse = featureCommentStandIn($admin->id + 1000);

    expect($rm->canEditFeatureComment($someoneElse))->toBeTrue();
    expect($rm->canDeleteFeatureComment($someoneElse))->toBeTrue();
});

it('denies deleting to an author who lacks delete crm features', function () {
    $author = rmAuthUser(['edit crm features']);
    $this->actingAs($author);

    expect(featureCommentsRm()->canDeleteFeatureComment(featureCommentStandIn($author->id)))->toBeFalse();
});

it('allows an author holding delete crm features to delete their own comment', function () {
    $author = rmAuthUser(['delete crm features']);
    $this->actingAs($author);

    expect(featureCommentsRm()->canDeleteFeatureComment(featureCommentStandIn($author->id)))->toBeTrue();
});

it('denies edit and delete when no record is supplied and the user is not an admin', function () {
    $this->actingAs(rmAuthUser(['edit crm features', 'delete crm features']));

    $rm = featureCommentsRm();

    expect($rm->canEditFeatureComment(null))->toBeFalse();
    expect($rm->canDeleteFeatureComment(null))->toBeFalse();
});

it('names the feature permissions it gates on', function () {
    expect(FeatureCommentsRelationManager::CREATE_PERMISSION)->toBe('edit crm features');
    expect(FeatureCommentsRelationManager::DELETE_PERMISSION)->toBe('delete crm features');
    expect(FeatureCommentsRelationManager::MODERATOR_ROLES)->toBe(['Owner', 'Admin']);
});
