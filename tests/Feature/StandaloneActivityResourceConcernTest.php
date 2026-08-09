<?php

use Filament\Actions\Action;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Customer;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrmFilament\Concerns\StandaloneActivityResource;
use VentureDrake\LaravelCrmFilament\Resources\Activities\ActivityResource;
use VentureDrake\LaravelCrmFilament\Resources\Calls\CallResource;
use VentureDrake\LaravelCrmFilament\Resources\Files\FileResource;
use VentureDrake\LaravelCrmFilament\Resources\Leads\LeadResource;
use VentureDrake\LaravelCrmFilament\Resources\Lunches\LunchResource;
use VentureDrake\LaravelCrmFilament\Resources\Meetings\MeetingResource;
use VentureDrake\LaravelCrmFilament\Resources\Notes\NoteResource;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

/**
 * Coverage for the shared StandaloneActivityResource concern used by
 * NoteResource / CallResource / MeetingResource / LunchResource / FileResource
 * / ActivityResource. Exercises buildParentUrl() across each null/miss branch
 * plus the happy path, and asserts openParentAction() returns a well-formed
 * Filament Action.
 */
beforeEach(function () {
    RoleSeeder::seed();

    $user = User::create([
        'name' => 'RM User',
        'email' => 'rm-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ])->assignRole('Owner');

    $this->actingAs($user);
});

function invokeBuildParentUrl(?string $type, mixed $id): ?string
{
    $method = new ReflectionMethod(NoteResource::class, 'buildParentUrl');
    $method->setAccessible(true);

    return $method->invoke(null, $type, $id);
}

function invokeOpenParentAction(string $typeColumn, string $idColumn): Action
{
    $method = new ReflectionMethod(NoteResource::class, 'openParentAction');
    $method->setAccessible(true);

    return $method->invoke(null, $typeColumn, $idColumn);
}

it('composes on every standalone activity resource', function () {
    foreach ([
        NoteResource::class,
        CallResource::class,
        MeetingResource::class,
        LunchResource::class,
        FileResource::class,
        ActivityResource::class,
    ] as $resource) {
        expect(class_uses_recursive($resource))->toContain(StandaloneActivityResource::class);
    }
});

it('buildParentUrl returns null when the type is missing', function () {
    expect(invokeBuildParentUrl(null, 1))->toBeNull();
});

it('buildParentUrl returns null when the id is missing', function () {
    expect(invokeBuildParentUrl(Lead::class, null))->toBeNull();
});

it('buildParentUrl returns null when the type has no ParentTypeOptions mapping', function () {
    // Customer appears in ParentTypeOptions::all() but has no dedicated
    // resource entry — buildParentUrl short-circuits to null.
    expect(invokeBuildParentUrl(Customer::class, 999))->toBeNull();
});

it('buildParentUrl returns null when the FQCN does not resolve to a class', function () {
    expect(invokeBuildParentUrl('App\\Bogus\\NotAModel', 1))->toBeNull();
});

it('buildParentUrl returns null when the referenced parent record does not exist', function () {
    expect(invokeBuildParentUrl(Lead::class, 999999))->toBeNull();
});

it('buildParentUrl returns the resource view URL keyed by external_id when the parent exists', function () {
    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Parent lead',
    ]);

    $url = invokeBuildParentUrl(Lead::class, $lead->id);

    expect($url)->not->toBeNull();
    expect($url)->toBe(LeadResource::getUrl('view', ['record' => $lead->external_id]));
});

it('openParentAction returns a Filament Action named openParent that opens in a new tab', function () {
    $action = invokeOpenParentAction('noteable_type', 'noteable_id');

    expect($action)->toBeInstanceOf(Action::class);
    expect($action->getName())->toBe('openParent');
    expect($action->getIcon())->toBe('heroicon-o-arrow-top-right-on-square');
    expect($action->shouldOpenUrlInNewTab())->toBeTrue();
});
