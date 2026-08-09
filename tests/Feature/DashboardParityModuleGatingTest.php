<?php

use Filament\Facades\Filament;
use Filament\Panel;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Pages\Dashboard;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;
use VentureDrake\LaravelCrmFilament\Widgets\CampaignPerformanceChart;
use VentureDrake\LaravelCrmFilament\Widgets\ContactsStatsOverview;
use VentureDrake\LaravelCrmFilament\Widgets\CrmStatsOverview;
use VentureDrake\LaravelCrmFilament\Widgets\DealsPipelineValueChart;
use VentureDrake\LaravelCrmFilament\Widgets\DealStatusDoughnutChart;
use VentureDrake\LaravelCrmFilament\Widgets\DealsValueStat;
use VentureDrake\LaravelCrmFilament\Widgets\LeadsByStageChart;
use VentureDrake\LaravelCrmFilament\Widgets\LeadsVsDealsChart;
use VentureDrake\LaravelCrmFilament\Widgets\MonthlyRevenueChart;
use VentureDrake\LaravelCrmFilament\Widgets\RecentActivityList;
use VentureDrake\LaravelCrmFilament\Widgets\TasksDueTodayList;

/**
 * Helper: mount the plugin on a fresh panel with the AC-named modules array,
 * set it as the current panel for the callback, and tear down cleanly.
 *
 * Mirrors the us008WithPanel helper in DashboardPageTest so tests here can
 * hit LaravelCrmPlugin::isModuleEnabled()-driven module resolution end-to-end.
 */
function parityGatingWithPanel(array $modules, callable $fn)
{
    $plugin = LaravelCrmPlugin::make()->modules($modules);
    $panel = Panel::make()->id('us009-parity-' . uniqid());
    $panel->plugin($plugin);
    $plugin->register($panel);
    Filament::setCurrentPanel($panel);

    try {
        return $fn($panel);
    } finally {
        Filament::setCurrentPanel(null);
    }
}

// ----------------------------------------------------------------------------
// Per-module dataset — each row lists (module key, gated widget FQCNs).
// ----------------------------------------------------------------------------
//
// Where a widget's gate is an OR across multiple modules (e.g.
// CrmStatsOverview: leads || deals), a single-module toggle only drops it
// when the OTHER module is also disabled — so those tests use a bare
// per-module dataset here that toggles ONE module at a time and asserts on
// the strictly module-gated widgets. Multi-module OR gates are covered by
// the standalone tests below.

dataset('parity_module_gates', [
    // Only 'deals' remains as a strictly-single-module-gated dashboard entry.
    // LeadsByStageChart + CampaignPerformanceChart were removed to match core /crm/dashboard
    // (regression guards below assert they stay absent regardless of module state).
    'deals' => ['deals', [DealsPipelineValueChart::class, DealStatusDoughnutChart::class]],
]);

// ----------------------------------------------------------------------------
// Single-module gate parametric tests
// ----------------------------------------------------------------------------

/**
 * The three data widgets are permission-gated as of 1.1.0 — with ActivityPolicy
 * now registered in core 2.4.0, "intentionally ungated" was no longer
 * defensible for RecentActivityList, and the same argument applies to the
 * tasks list and the contacts stat. Every widget-layout assertion therefore
 * needs an authenticated user who holds them.
 */
beforeEach(function () {
    RoleSeeder::seed();

    $user = User::create([
        'name' => 'Dashboard Tester',
        'email' => 'dashboard-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $user->assignRole('Owner');

    $this->actingAs($user->fresh());
});

it('gated widgets are absent from Dashboard::getWidgets() when the module is disabled', function (string $module, array $gatedWidgets) {
    // Start with everything ON so unrelated OR-gated widgets stay present.
    $enabled = [
        'leads' => true, 'deals' => true, 'quotes' => true, 'orders' => true,
        'invoices' => true, 'email-marketing' => true,
    ];
    $enabled[$module] = false;

    $widgets = parityGatingWithPanel($enabled, fn () => (new Dashboard)->getWidgets());

    foreach ($gatedWidgets as $widgetClass) {
        expect($widgets)->not->toContain($widgetClass);
    }
})->with('parity_module_gates');

it('gated widgets are present in Dashboard::getWidgets() when the module is enabled', function (string $module, array $gatedWidgets) {
    // Enable ONLY the target module (plus supporting OR-gate satisfiers).
    // For CampaignPerformanceChart (email-marketing) we don't need any other
    // module; for LeadsByStageChart (leads) we don't need deals; for
    // Deals* widgets we don't need leads.
    $enabled = [
        'leads' => true, 'deals' => true, 'quotes' => true, 'orders' => true,
        'invoices' => true, 'email-marketing' => true,
    ];

    $widgets = parityGatingWithPanel($enabled, fn () => (new Dashboard)->getWidgets());

    foreach ($gatedWidgets as $widgetClass) {
        expect($widgets)->toContain($widgetClass);
    }
})->with('parity_module_gates');

// ----------------------------------------------------------------------------
// Regression guards — widgets removed to match core /crm/dashboard.
// ----------------------------------------------------------------------------

it('LeadsByStageChart is never present on the Dashboard (removed to match core /crm/dashboard)', function () {
    foreach ([
        ['leads' => true, 'deals' => true, 'quotes' => true, 'orders' => true, 'invoices' => true, 'email-marketing' => true],
        ['leads' => false, 'deals' => false, 'quotes' => false, 'orders' => false, 'invoices' => false, 'email-marketing' => false],
    ] as $modules) {
        $widgets = parityGatingWithPanel($modules, fn () => (new Dashboard)->getWidgets());
        expect($widgets)->not->toContain(LeadsByStageChart::class);
    }
});

it('CampaignPerformanceChart is never present on the Dashboard (removed to match core /crm/dashboard)', function () {
    foreach ([
        ['leads' => true, 'deals' => true, 'quotes' => true, 'orders' => true, 'invoices' => true, 'email-marketing' => true],
        ['leads' => false, 'deals' => false, 'quotes' => false, 'orders' => false, 'invoices' => false, 'email-marketing' => false],
    ] as $modules) {
        $widgets = parityGatingWithPanel($modules, fn () => (new Dashboard)->getWidgets());
        expect($widgets)->not->toContain(CampaignPerformanceChart::class);
    }
});

// ----------------------------------------------------------------------------
// OR-gated widgets: assert both halves of the OR gate matter.
// ----------------------------------------------------------------------------

it('CrmStatsOverview drops when BOTH leads AND deals are disabled', function () {
    $widgets = parityGatingWithPanel([
        'leads' => false, 'deals' => false,
        'quotes' => true, 'orders' => true, 'invoices' => true, 'email-marketing' => true,
    ], fn () => (new Dashboard)->getWidgets());

    expect($widgets)->not->toContain(CrmStatsOverview::class);
});

it('CrmStatsOverview stays present when either leads OR deals is enabled', function () {
    $widgetsLeadsOnly = parityGatingWithPanel([
        'leads' => true, 'deals' => false,
        'quotes' => false, 'orders' => false, 'invoices' => false, 'email-marketing' => false,
    ], fn () => (new Dashboard)->getWidgets());
    expect($widgetsLeadsOnly)->toContain(CrmStatsOverview::class);

    $widgetsDealsOnly = parityGatingWithPanel([
        'leads' => false, 'deals' => true,
        'quotes' => false, 'orders' => false, 'invoices' => false, 'email-marketing' => false,
    ], fn () => (new Dashboard)->getWidgets());
    expect($widgetsDealsOnly)->toContain(CrmStatsOverview::class);
});

it('LeadsVsDealsChart drops when BOTH leads AND deals are disabled', function () {
    $widgets = parityGatingWithPanel([
        'leads' => false, 'deals' => false,
        'quotes' => true, 'orders' => true, 'invoices' => true,
    ], fn () => (new Dashboard)->getWidgets());

    expect($widgets)->not->toContain(LeadsVsDealsChart::class);
});

it('LeadsVsDealsChart stays present when either leads OR deals is enabled', function () {
    $widgetsLeadsOnly = parityGatingWithPanel([
        'leads' => true, 'deals' => false,
    ], fn () => (new Dashboard)->getWidgets());
    expect($widgetsLeadsOnly)->toContain(LeadsVsDealsChart::class);

    $widgetsDealsOnly = parityGatingWithPanel([
        'leads' => false, 'deals' => true,
    ], fn () => (new Dashboard)->getWidgets());
    expect($widgetsDealsOnly)->toContain(LeadsVsDealsChart::class);
});

it('DealsValueStat drops when quotes AND orders AND invoices are ALL disabled', function () {
    $widgets = parityGatingWithPanel([
        'leads' => true, 'deals' => true,
        'quotes' => false, 'orders' => false, 'invoices' => false,
    ], fn () => (new Dashboard)->getWidgets());

    expect($widgets)->not->toContain(DealsValueStat::class);
});

it('DealsValueStat stays present when any of quotes/orders/invoices is enabled', function () {
    foreach (['quotes', 'orders', 'invoices'] as $onModule) {
        $modules = [
            'leads' => false, 'deals' => false,
            'quotes' => false, 'orders' => false, 'invoices' => false,
        ];
        $modules[$onModule] = true;

        $widgets = parityGatingWithPanel($modules, fn () => (new Dashboard)->getWidgets());
        expect($widgets)->toContain(DealsValueStat::class);
    }
});

it('MonthlyRevenueChart drops when BOTH invoices AND orders are disabled', function () {
    $widgets = parityGatingWithPanel([
        'leads' => true, 'deals' => true,
        'quotes' => true, 'orders' => false, 'invoices' => false,
    ], fn () => (new Dashboard)->getWidgets());

    expect($widgets)->not->toContain(MonthlyRevenueChart::class);
});

it('MonthlyRevenueChart stays present when either invoices OR orders is enabled', function () {
    $invoicesOnly = parityGatingWithPanel([
        'invoices' => true, 'orders' => false,
    ], fn () => (new Dashboard)->getWidgets());
    expect($invoicesOnly)->toContain(MonthlyRevenueChart::class);

    $ordersOnly = parityGatingWithPanel([
        'invoices' => false, 'orders' => true,
    ], fn () => (new Dashboard)->getWidgets());
    expect($ordersOnly)->toContain(MonthlyRevenueChart::class);
});

// ----------------------------------------------------------------------------
// Module-independent widgets — present regardless of module state for a user
// who holds the permissions they read against.
// ----------------------------------------------------------------------------

it('ContactsStatsOverview, TasksDueTodayList, RecentActivityList ignore module state', function () {
    $everythingOff = parityGatingWithPanel([
        'leads' => false, 'deals' => false, 'quotes' => false, 'orders' => false,
        'invoices' => false, 'email-marketing' => false,
    ], fn () => (new Dashboard)->getWidgets());

    expect($everythingOff)->toContain(ContactsStatsOverview::class)
        ->toContain(TasksDueTodayList::class)
        ->toContain(RecentActivityList::class);
});

it('drops the three data widgets for a user without the permissions they read', function () {
    $employee = User::create([
        'name' => 'No Permissions',
        'email' => 'no-perms-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);

    $this->actingAs($employee->fresh());

    $widgets = parityGatingWithPanel([
        'leads' => true, 'deals' => true, 'quotes' => true, 'orders' => true,
        'invoices' => true, 'email-marketing' => true,
    ], fn () => (new Dashboard)->getWidgets());

    expect($widgets)->not->toContain(ContactsStatsOverview::class)
        ->not->toContain(TasksDueTodayList::class)
        ->not->toContain(RecentActivityList::class)
        // The module-gated charts are unaffected — the Dashboard page itself
        // stays reachable, because a user who can log in has to land somewhere.
        ->toContain(CrmStatsOverview::class);
});
