<?php

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrmFilament\Widgets\ContactsStatsOverview;
use VentureDrake\LaravelCrmFilament\Widgets\CrmStatsOverview;
use VentureDrake\LaravelCrmFilament\Widgets\DealsValueStat;

it('CrmStatsOverview extends StatsOverviewWidget and returns 3 columns', function () {
    expect(is_subclass_of(CrmStatsOverview::class, StatsOverviewWidget::class))->toBeTrue();

    $ref = new ReflectionMethod(CrmStatsOverview::class, 'getColumns');
    $ref->setAccessible(true);
    expect($ref->invoke(new CrmStatsOverview))->toBe(3);
});

it('DealsValueStat extends StatsOverviewWidget and returns 4 columns', function () {
    expect(is_subclass_of(DealsValueStat::class, StatsOverviewWidget::class))->toBeTrue();

    $ref = new ReflectionMethod(DealsValueStat::class, 'getColumns');
    $ref->setAccessible(true);
    expect($ref->invoke(new DealsValueStat))->toBe(4);
});

it('ContactsStatsOverview extends StatsOverviewWidget and returns 3 columns', function () {
    expect(is_subclass_of(ContactsStatsOverview::class, StatsOverviewWidget::class))->toBeTrue();

    $ref = new ReflectionMethod(ContactsStatsOverview::class, 'getColumns');
    $ref->setAccessible(true);
    expect($ref->invoke(new ContactsStatsOverview))->toBe(3);
});

it('each stats widget getHeading() renders localised label with period suffix', function () {
    foreach ([CrmStatsOverview::class, DealsValueStat::class, ContactsStatsOverview::class] as $widget) {
        $heading = (new $widget)->getHeading();
        expect($heading)->toBeString()->not->toBeEmpty();
        // Period suffix key is used, so heading string should contain a delimiter " · " and the period label.
        expect($heading)->toContain('·');
    }
});

it('CrmStatsOverview returns Sales stats keyed on new_leads, pipeline_value, deals_won when leads+deals enabled', function () {
    config(['laravel-crm.modules' => ['leads', 'deals']]);

    $ref = new ReflectionMethod(CrmStatsOverview::class, 'getStats');
    $ref->setAccessible(true);
    $stats = $ref->invoke(new CrmStatsOverview);

    expect($stats)->toHaveCount(3);
    foreach ($stats as $stat) {
        expect($stat)->toBeInstanceOf(Stat::class);
    }

    $labels = array_map(fn (Stat $s) => $s->getLabel(), $stats);
    expect($labels[0])->toBe(__('laravel-crm-filament::labels.dashboard.new_leads'));
    expect($labels[1])->toBe(__('laravel-crm-filament::labels.dashboard.pipeline_value'));
    expect($labels[2])->toBe(__('laravel-crm-filament::labels.dashboard.deals_won'));
});

it('CrmStatsOverview omits leads stat when leads module is off', function () {
    config(['laravel-crm.modules' => ['deals']]);

    $ref = new ReflectionMethod(CrmStatsOverview::class, 'getStats');
    $ref->setAccessible(true);
    $stats = $ref->invoke(new CrmStatsOverview);

    expect($stats)->toHaveCount(2);
    $labels = array_map(fn (Stat $s) => $s->getLabel(), $stats);
    expect($labels)->not->toContain(__('laravel-crm-filament::labels.dashboard.new_leads'));
    expect($labels)->toContain(__('laravel-crm-filament::labels.dashboard.pipeline_value'));
});

it('CrmStatsOverview omits deals stats when deals module is off', function () {
    config(['laravel-crm.modules' => ['leads']]);

    $ref = new ReflectionMethod(CrmStatsOverview::class, 'getStats');
    $ref->setAccessible(true);
    $stats = $ref->invoke(new CrmStatsOverview);

    expect($stats)->toHaveCount(1);
    expect($stats[0]->getLabel())->toBe(__('laravel-crm-filament::labels.dashboard.new_leads'));
});

it('DealsValueStat returns Finance stats keyed on outstanding, paid, quotes, orders when all modules enabled', function () {
    config(['laravel-crm.modules' => ['invoices', 'quotes', 'orders']]);

    $ref = new ReflectionMethod(DealsValueStat::class, 'getStats');
    $ref->setAccessible(true);
    $stats = $ref->invoke(new DealsValueStat);

    expect($stats)->toHaveCount(4);
    $labels = array_map(fn (Stat $s) => $s->getLabel(), $stats);
    expect($labels[0])->toBe(__('laravel-crm-filament::labels.dashboard.outstanding_invoices'));
    expect($labels[1])->toBe(__('laravel-crm-filament::labels.dashboard.invoices_paid'));
    expect($labels[2])->toBe(__('laravel-crm-filament::labels.dashboard.quotes_created'));
    expect($labels[3])->toBe(__('laravel-crm-filament::labels.dashboard.orders_created'));
});

it('DealsValueStat omits per-module stats when the owning module is off', function () {
    config(['laravel-crm.modules' => ['invoices']]);

    $ref = new ReflectionMethod(DealsValueStat::class, 'getStats');
    $ref->setAccessible(true);
    $stats = $ref->invoke(new DealsValueStat);

    expect($stats)->toHaveCount(2);
    $labels = array_map(fn (Stat $s) => $s->getLabel(), $stats);
    expect($labels)->toContain(__('laravel-crm-filament::labels.dashboard.outstanding_invoices'));
    expect($labels)->toContain(__('laravel-crm-filament::labels.dashboard.invoices_paid'));
    expect($labels)->not->toContain(__('laravel-crm-filament::labels.dashboard.quotes_created'));
    expect($labels)->not->toContain(__('laravel-crm-filament::labels.dashboard.orders_created'));
});

it('ContactsStatsOverview leads with a new-contacts stat carrying the people/organizations breakdown', function () {
    $ref = new ReflectionMethod(ContactsStatsOverview::class, 'getStats');
    $ref->setAccessible(true);
    $stats = $ref->invoke(new ContactsStatsOverview);

    expect($stats)->toHaveCount(3);
    expect($stats[0])->toBeInstanceOf(Stat::class);
    expect($stats[0]->getLabel())->toBe(__('laravel-crm-filament::labels.dashboard.new_contacts'));

    // Description should be the resolved 'people_orgs_breakdown' translation with
    // placeholders substituted (both values default to 0 for a fresh DB).
    $expected = __('laravel-crm-filament::labels.dashboard.people_orgs_breakdown', [
        'people' => 0,
        'organizations' => 0,
    ]);
    expect($stats[0]->getDescription())->toBe($expected);
});

it('CrmStatsOverview renders without errors when both modules are enabled and no rows exist', function () {
    config(['laravel-crm.modules' => ['leads', 'deals']]);
    $ref = new ReflectionMethod(CrmStatsOverview::class, 'getStats');
    $ref->setAccessible(true);
    $stats = $ref->invoke(new CrmStatsOverview);
    expect($stats)->toBeArray()->toHaveCount(3);
});

it('DealsValueStat renders without errors when all modules are enabled and no rows exist', function () {
    config(['laravel-crm.modules' => ['invoices', 'quotes', 'orders']]);
    $ref = new ReflectionMethod(DealsValueStat::class, 'getStats');
    $ref->setAccessible(true);
    $stats = $ref->invoke(new DealsValueStat);
    expect($stats)->toBeArray()->toHaveCount(4);
});

it('ContactsStatsOverview renders without errors when no rows exist', function () {
    $ref = new ReflectionMethod(ContactsStatsOverview::class, 'getStats');
    $ref->setAccessible(true);
    $stats = $ref->invoke(new ContactsStatsOverview);
    expect($stats)->toBeArray()->toHaveCount(3);
});

it('ContactsStatsOverview shows all-time people and organization totals with the 30-day count beneath', function () {
    Person::create(['first_name' => 'Old', 'last_name' => 'Timer'])
        ->forceFill(['created_at' => now()->subDays(90)])->save();
    Person::create(['first_name' => 'New', 'last_name' => 'Comer']);
    Organization::create(['name' => 'Acme']);

    $ref = new ReflectionMethod(ContactsStatsOverview::class, 'getStats');
    $ref->setAccessible(true);
    [, $people, $organizations] = $ref->invoke(new ContactsStatsOverview);

    expect($people->getLabel())->toBe(__('laravel-crm-filament::labels.sales.people'))
        ->and($people->getValue())->toBe('2')
        ->and($people->getDescription())->toBe(__('laravel-crm-filament::labels.dashboard.new_count', ['count' => 1]))
        ->and($organizations->getLabel())->toBe(__('laravel-crm-filament::labels.sales.organizations'))
        ->and($organizations->getValue())->toBe('1')
        ->and($organizations->getDescription())->toBe(__('laravel-crm-filament::labels.dashboard.new_count', ['count' => 1]));
});
