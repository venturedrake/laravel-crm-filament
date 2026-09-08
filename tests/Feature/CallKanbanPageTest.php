<?php

use Filament\Resources\Pages\Page as ResourcePage;
use VentureDrake\LaravelCrm\Models\Call;
use VentureDrake\LaravelCrmFilament\Resources\Calls\CallResource;
use VentureDrake\LaravelCrmFilament\Resources\Calls\Pages\CallKanban;

it('declares the CallKanban as a sub-resource page of CallResource', function () {
    expect((new ReflectionClass(CallKanban::class))->getStaticPropertyValue('resource'))
        ->toBe(CallResource::class);
    expect(is_subclass_of(CallKanban::class, ResourcePage::class))->toBeTrue();
});

it('registers the kanban route on the Calls resource', function () {
    expect(CallResource::getPages())->toHaveKey('kanban');
});

it('exposes moveCall on CallKanban as a public two-arg method', function () {
    $reflection = new ReflectionMethod(CallKanban::class, 'moveCall');
    expect($reflection->isPublic())->toBeTrue();
    expect($reflection->getNumberOfRequiredParameters())->toBe(2);
});

it('groups calls into planned and done columns', function () {
    $blade = file_get_contents(__DIR__ . '/../../resources/views/calls/kanban.blade.php');
    foreach (['planned', 'done'] as $status) {
        expect($blade)->toContain("'{$status}' =>");
    }
    expect($blade)->toContain('data-status="{{ $status }}"');
});

it('buckets calls by finish_at presence', function () {
    $planned = Call::create([
        'name' => 'Planned call',
        'start_at' => now()->addHour(),
    ]);

    $done = Call::create([
        'name' => 'Done call',
        'start_at' => now()->subDay(),
        'finish_at' => now()->subDay()->addHour(),
    ]);

    $page = new CallKanban;
    $byStatus = $page->getCallsByStatus();

    expect($byStatus)->toHaveKeys(['planned', 'done']);
    expect($byStatus['planned']->pluck('id')->all())->toContain($planned->id);
    expect($byStatus['planned']->pluck('id')->all())->not->toContain($done->id);
    expect($byStatus['done']->pluck('id')->all())->toContain($done->id);
    expect($byStatus['done']->pluck('id')->all())->not->toContain($planned->id);
});

it('drag-to-done stamps finish_at and persists', function () {
    actingAsCrmAdmin();

    $call = Call::create([
        'name' => 'Planned',
        'start_at' => now()->addHour(),
    ]);

    expect($call->finish_at)->toBeNull();

    (new CallKanban)->moveCall($call->external_id, 'done');

    $call->refresh();
    expect($call->finish_at)->not->toBeNull();
});

it('drag-back-to-planned clears finish_at and persists', function () {
    actingAsCrmAdmin();

    $call = Call::create([
        'name' => 'Done',
        'start_at' => now()->subDay(),
        'finish_at' => now()->subDay()->addHour(),
    ]);

    expect($call->finish_at)->not->toBeNull();

    (new CallKanban)->moveCall($call->external_id, 'planned');

    $call->refresh();
    expect($call->finish_at)->toBeNull();
});

it('drag to done is idempotent when call already done', function () {
    actingAsCrmAdmin();

    $when = now()->subDays(2);
    $call = Call::create([
        'name' => 'Already done',
        'start_at' => $when->copy()->subHour(),
        'finish_at' => $when,
    ]);

    $before = $call->fresh()->finish_at->toDateTimeString();
    (new CallKanban)->moveCall($call->external_id, 'done');
    $after = $call->fresh()->finish_at->toDateTimeString();

    expect($after)->toBe($before);
});

it('moveCall silently returns when the call cannot be found', function () {
    (new CallKanban)->moveCall('non-existent-uuid', 'done');
    expect(true)->toBeTrue();
});
