<?php

use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use VentureDrake\LaravelCrmFilament\Pages\ClickSendIntegration;
use VentureDrake\LaravelCrmFilament\Pages\Integrations;

/**
 * IntegrationsSubNavigationTest — regression gate for the sub-navigation
 * series (US-001 labels + US-002 Integrations page + US-003 ClickSend page).
 *
 * Locks every AC bullet in one focused file:
 * - Shape: both pages' getSubNavigation() return NavigationItem[] with
 *   the expected URLs.
 * - Module gate: ClickSend tab appears only when the sms-marketing module
 *   is enabled in `config('laravel-crm.modules')`.
 * - Regression guard: the pre-parity `manageClickSend` header action is
 *   no longer on Integrations::getHeaderActions().
 * - Description: the Xero Section carries the `integrations.xero_description`
 *   translation string.
 * - Position: getSubNavigationPosition() returns SubNavigationPosition::Top
 *   on both pages.
 * - Locale parity: integrations.xero_description exists in en/fr/es.
 */
it('Integrations::getSubNavigation() returns NavigationItem[] with Xero present when sms-marketing is enabled', function () {
    config(['laravel-crm.modules' => ['sms-marketing']]);

    $page = new Integrations;
    $tabs = $page->getSubNavigation();

    expect($tabs)->toBeArray();
    expect(count($tabs))->toBeGreaterThanOrEqual(1);
    foreach ($tabs as $tab) {
        expect($tab)->toBeInstanceOf(NavigationItem::class);
    }

    // Xero tab is always present (it's the Integrations page itself).
    $urls = array_map(fn (NavigationItem $t) => $t->getUrl(), $tabs);
    expect($urls)->toContain(Integrations::getUrl());
});

it('ClickSendIntegration::getSubNavigation() returns the same NavigationItem[] set as Integrations', function () {
    config(['laravel-crm.modules' => ['sms-marketing']]);

    $csTabs = (new ClickSendIntegration)->getSubNavigation();
    $intTabs = (new Integrations)->getSubNavigation();

    expect(count($csTabs))->toBe(count($intTabs));
    foreach ($csTabs as $tab) {
        expect($tab)->toBeInstanceOf(NavigationItem::class);
    }

    // Both pages surface the same tab set — the Integrations sub-nav helper
    // is the single source of truth.
    $csUrls = array_map(fn (NavigationItem $t) => $t->getUrl(), $csTabs);
    $intUrls = array_map(fn (NavigationItem $t) => $t->getUrl(), $intTabs);
    expect($csUrls)->toBe($intUrls);
});

it('surfaces the ClickSend tab when sms-marketing module is enabled', function () {
    config(['laravel-crm.modules' => ['sms-marketing']]);

    $tabs = Integrations::integrationTabs();
    $urls = array_map(fn (NavigationItem $t) => $t->getUrl(), $tabs);

    expect($urls)->toContain(ClickSendIntegration::getUrl());
});

it('hides the ClickSend tab when sms-marketing module is disabled', function () {
    // A configured list that omits sms-marketing, NOT an empty array: core's
    // module rule reads a falsy `laravel-crm.modules` as "unconfigured, so
    // everything is on", so `[]` enables the tab rather than hiding it.
    config(['laravel-crm.modules' => ['leads', 'deals']]);

    $tabs = Integrations::integrationTabs();
    $urls = array_map(fn (NavigationItem $t) => $t->getUrl(), $tabs);

    expect($urls)->not->toContain(ClickSendIntegration::getUrl());
    // Xero is always there.
    expect($urls)->toContain(Integrations::getUrl());
});

it('surfaces the ClickSend tab when laravel-crm.modules is empty, matching core', function () {
    config(['laravel-crm.modules' => []]);

    $urls = array_map(fn (NavigationItem $t) => $t->getUrl(), Integrations::integrationTabs());

    expect($urls)->toContain(ClickSendIntegration::getUrl());
});

it('does not register the pre-parity manageClickSend header action on Integrations', function () {
    $method = new ReflectionMethod(Integrations::class, 'getHeaderActions');
    $method->setAccessible(true);
    $actions = $method->invoke(new Integrations);

    $names = array_map(fn ($a) => $a->getName(), $actions);
    expect($names)->not->toContain('manageClickSend');
});

it('carries the integrations.xero_description translation on the Xero Section', function () {
    $source = file_get_contents((new ReflectionClass(Integrations::class))->getFileName());

    // The Xero Section's ->description() call routes through the AC-named
    // translation key rather than the previous xeroStatusLine() runtime string.
    expect($source)->toContain('labels.integrations.xero_description');
});

it('Integrations::getSubNavigationPosition() returns SubNavigationPosition::Top', function () {
    expect(Integrations::getSubNavigationPosition())->toBe(SubNavigationPosition::Top);
});

it('ClickSendIntegration::getSubNavigationPosition() returns SubNavigationPosition::Top', function () {
    expect(ClickSendIntegration::getSubNavigationPosition())->toBe(SubNavigationPosition::Top);
});

it('integrations.xero_description translation exists in en/fr/es as non-empty strings', function () {
    $root = dirname(__DIR__, 2);
    foreach (['en', 'fr', 'es'] as $locale) {
        $labels = require $root . "/resources/lang/{$locale}/labels.php";
        expect($labels)->toHaveKey('integrations');
        expect($labels['integrations'])->toHaveKey('xero_description');
        expect($labels['integrations']['xero_description'])->toBeString();
        expect(trim($labels['integrations']['xero_description']))->not->toBe('');
    }
});
