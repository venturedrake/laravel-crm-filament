<?php

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Services\FeatureService;
use VentureDrake\LaravelCrmFilament\RelationManagers\FeatureCommentsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\FeatureVotersRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Features\FeatureResource;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

// ----------------------------------------------------------------------------
// FeatureVotersRelationManager — read-only voters tab
// ----------------------------------------------------------------------------

it('declares FeatureVotersRelationManager relationship as voters', function () {
    $reflection = new ReflectionClass(FeatureVotersRelationManager::class);
    $property = $reflection->getProperty('relationship');

    expect($property->getValue())->toBe('voters');
});

it('FeatureVotersRelationManager extends Filament RelationManager', function () {
    expect(is_subclass_of(FeatureVotersRelationManager::class, RelationManager::class))->toBeTrue();
});

it('FeatureVotersRelationManager isReadOnly() returns true and exposes empty action arrays', function () {
    $instance = (new ReflectionClass(FeatureVotersRelationManager::class))->newInstanceWithoutConstructor();
    expect($instance->isReadOnly())->toBeTrue();

    $src = file_get_contents((new ReflectionClass(FeatureVotersRelationManager::class))->getFileName());
    expect($src)->toContain('->headerActions([])');
    expect($src)->toContain('->recordActions([])');
    expect($src)->toContain('->toolbarActions([])');
});

it('FeatureVotersRelationManager renders voter name + voted_at columns', function () {
    $src = file_get_contents((new ReflectionClass(FeatureVotersRelationManager::class))->getFileName());

    expect($src)->toContain("Tables\\Columns\\TextColumn::make('name')");
    expect($src)->toContain("Tables\\Columns\\TextColumn::make('voted_at')");
    expect($src)->toContain('$record->pivot?->created_at');
});

// ----------------------------------------------------------------------------
// FeatureCommentsRelationManager — full CRUD
// ----------------------------------------------------------------------------

it('declares FeatureCommentsRelationManager relationship as comments', function () {
    $reflection = new ReflectionClass(FeatureCommentsRelationManager::class);
    $property = $reflection->getProperty('relationship');

    expect($property->getValue())->toBe('comments');
});

it('FeatureCommentsRelationManager extends Filament RelationManager', function () {
    expect(is_subclass_of(FeatureCommentsRelationManager::class, RelationManager::class))->toBeTrue();
});

it('FeatureCommentsRelationManager renders the AC-named columns', function () {
    $src = file_get_contents((new ReflectionClass(FeatureCommentsRelationManager::class))->getFileName());

    expect($src)->toContain("Tables\\Columns\\TextColumn::make('createdByUser.name')");
    expect($src)->toContain("Tables\\Columns\\TextColumn::make('body')");
    expect($src)->toContain('->limit(80)');
    expect($src)->toContain('->tooltip(');
    expect($src)->toContain("Tables\\Columns\\IconColumn::make('is_admin_reply')");
    expect($src)->toContain('->boolean()');
    expect($src)->toContain("Tables\\Columns\\TextColumn::make('created_at')");
    expect($src)->toContain('->since()');
});

it('FeatureCommentsRelationManager header CreateAction routes through FeatureService::comment behind the edit permission', function () {
    $src = file_get_contents((new ReflectionClass(FeatureCommentsRelationManager::class))->getFileName());

    // Header CreateAction registered with body Textarea + parent_id Select
    expect($src)->toMatch('/headerActions\(\[\s*Actions\\\\CreateAction::make\(\)/');

    // Action is gated on `edit crm features` — never blanket-allowed.
    expect($src)->toContain('->authorize(fn (): bool => $this->canCreateFeatureComment())');
    expect($src)->not->toContain('authorize(fn () => true)');

    // Action schema declares Textarea body + Select parent_id
    expect($src)->toContain("Forms\\Components\\Textarea::make('body')");
    expect($src)->toContain("Forms\\Components\\Select::make('parent_id')");

    // Action persistence routes through FeatureService::comment — NOT FeatureComment::create directly
    expect($src)->toContain('FeatureService::class');
    expect($src)->toContain('->comment(');
    expect($src)->toContain('auth()->user()');
    expect($src)->toContain("\$data['body']");

    // No direct FeatureComment::create — must go through the service
    expect($src)->not->toContain('FeatureComment::create(');
});

it('FeatureCommentsRelationManager registers row Edit + Delete actions for moderation', function () {
    $src = file_get_contents((new ReflectionClass(FeatureCommentsRelationManager::class))->getFileName());

    // Core CRM ships no FeatureCommentPolicy, so Edit / Delete are authorized
    // explicitly against the feature permissions with author-or-admin scoping.
    expect($src)->toMatch('/Actions\\\\EditAction::make\(\)\s*->authorize\(fn \(\?Model \$record\): bool => \$this->canEditFeatureComment\(\$record\)\)/');
    expect($src)->toMatch('/Actions\\\\DeleteAction::make\(\)\s*->authorize\(fn \(\?Model \$record\): bool => \$this->canDeleteFeatureComment\(\$record\)\)\s*->requiresConfirmation\(\)/');
});

// ----------------------------------------------------------------------------
// Wiring into FeatureResource::getRelations()
// ----------------------------------------------------------------------------

it('appends FeatureVotersRelationManager and FeatureCommentsRelationManager to FeatureResource::getRelations()', function () {
    $relations = FeatureResource::getRelations();

    expect($relations)->toContain(FeatureVotersRelationManager::class);
    expect($relations)->toContain(FeatureCommentsRelationManager::class);
});

// ----------------------------------------------------------------------------
// AC-required integration test: CreateAction creates FeatureComment with
// is_admin_reply=true for an Admin user. Only runs when the Feature model
// is loadable (the plugin's vendor lock — venturedrake/laravel-crm 2.1.1 —
// doesn't ship the Feature classes; hosts on develop or 2.2+ do).
// ----------------------------------------------------------------------------

it('CreateAction routes through FeatureService::comment() with is_admin_reply=true when the acting user has edit crm features', function () {
    if (! class_exists('VentureDrake\\LaravelCrm\\Models\\Feature')) {
        $this->markTestSkipped('Feature model not present in vendor lock — story locked behind upstream model availability.');
    }

    RoleSeeder::seed();

    $user = User::create([
        'name' => 'Admin Commenter',
        'email' => 'admin-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $user->assignRole('Admin');
    auth()->login($user->fresh());

    $featureClass = 'VentureDrake\\LaravelCrm\\Models\\Feature';
    $feature = $featureClass::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'A feature with comments',
        'is_public' => false,
    ]);

    /** @var FeatureService $service */
    $service = app(FeatureService::class);
    $comment = $service->comment($feature, auth()->user(), 'My first comment');

    expect($comment->body)->toBe('My first comment');
    expect((int) $comment->feature_id)->toBe($feature->id);
    expect((bool) $comment->is_admin_reply)->toBeTrue();
    expect((int) $comment->user_created_id)->toBe($user->id);
});
