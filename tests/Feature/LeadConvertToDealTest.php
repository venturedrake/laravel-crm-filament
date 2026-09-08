<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrmFilament\Resources\Leads\LeadResource;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\LeadKanban;

it('LeadResource::doConvertToDeal exists with the correct signature', function () {
    expect(method_exists(LeadResource::class, 'doConvertToDeal'))->toBeTrue();

    $reflection = new ReflectionMethod(LeadResource::class, 'doConvertToDeal');
    expect($reflection->isPublic())->toBeTrue();
    expect($reflection->isStatic())->toBeTrue();
    expect($reflection->getNumberOfRequiredParameters())->toBe(1);

    $param = $reflection->getParameters()[0];
    expect($param->getType()?->getName())->toBe(Lead::class);
    expect($reflection->getReturnType()?->getName())->toBe('void');
});

it('doConvertToDeal creates a Deal with the lead title/amount and stamps converted_at', function () {
    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Acme Q3 expansion',
        'amount' => 2500, // setter multiplies by 100 -> stored as 250000
        'currency' => 'USD',
    ]);

    expect($lead->converted_at)->toBeNull();
    $storedAmount = (int) $lead->fresh()->amount;

    LeadResource::doConvertToDeal($lead->fresh());

    $lead->refresh();
    expect($lead->converted_at)->not->toBeNull();

    $deal = Deal::query()->where('title', 'Acme Q3 expansion')->first();
    expect($deal)->not->toBeNull();
    // Lead amount divided by 100 → Deal setter multiplies by 100 → same stored value.
    expect((int) $deal->amount)->toBe($storedAmount);
    expect($deal->currency)->toBe('USD');
});

it('doConvertToDeal is a no-op when the lead is already converted', function () {
    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Already converted',
        'amount' => 1000,
        'currency' => 'USD',
        'converted_at' => now()->subDay(),
    ]);

    $originalConvertedAt = $lead->fresh()->converted_at;
    $dealsBefore = Deal::query()->count();

    LeadResource::doConvertToDeal($lead->fresh());

    expect(Deal::query()->count())->toBe($dealsBefore);
    expect($lead->fresh()->converted_at->toDateTimeString())->toBe($originalConvertedAt->toDateTimeString());
});

it('LeadKanban::convertToDeal resolves the lead by external_id and delegates to the helper', function () {
    actingAsCrmAdmin();

    $lead = Lead::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Kanban convert path',
        'amount' => 5000,
        'currency' => 'USD',
    ]);

    // The LeadObserver may stamp a fresh external_id on creating — read the actual stored value.
    $extId = $lead->fresh()->external_id;
    expect($extId)->not->toBeEmpty();

    $page = new LeadKanban;
    $page->convertToDeal($extId);

    expect(Deal::query()->where('title', 'Kanban convert path')->exists())->toBeTrue();
    expect(Lead::query()->where('external_id', $extId)->whereNotNull('converted_at')->exists())->toBeTrue();
});

it('LeadKanban::convertToDeal contains no duplicated conversion logic and delegates to LeadResource', function () {
    $src = file_get_contents(
        dirname(__DIR__, 2) . '/src/Resources/Leads/Pages/LeadKanban.php'
    );

    expect($src)->toContain('LeadResource::doConvertToDeal(');
    expect($src)->not->toContain('new Fluent([');
    expect($src)->not->toContain('DealService::class');
});

it('LeadKanban::convertToDeal keeps its public single-string-arg signature', function () {
    $reflection = new ReflectionMethod(LeadKanban::class, 'convertToDeal');
    expect($reflection->isPublic())->toBeTrue();
    expect($reflection->getNumberOfRequiredParameters())->toBe(1);

    $param = $reflection->getParameters()[0];
    expect($param->getType()?->getName())->toBe('string');
});
