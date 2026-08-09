<?php

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use VentureDrake\LaravelCrmFilament\Resources\Deals\DealResource;
use VentureDrake\LaravelCrmFilament\Resources\Deals\Pages\ViewDeal;
use VentureDrake\LaravelCrmFilament\Resources\Leads\LeadResource;
use VentureDrake\LaravelCrmFilament\Resources\Leads\Pages\ViewLead;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\ViewQuote;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\QuoteResource;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Show Action Order Tester',
        'email' => 'show-action-order-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);
});

function showActionOrderInvokeHeaderActions(string $page): array
{
    $instance = (new ReflectionClass($page))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($page, 'getHeaderActions');
    $method->setAccessible(true);

    return $method->invoke($instance);
}

it('LeadResource::backToIndexAction returns a gray arrow-left Action pointing at the index URL', function () {
    $action = LeadResource::backToIndexAction();

    expect($action)->toBeInstanceOf(Action::class);
    expect($action->getName())->toBe('backToIndex');
    expect($action->getColor())->toBe('gray');
    expect($action->getIcon())->toBe('heroicon-o-arrow-left');
    expect($action->getUrl())->toBe(LeadResource::getUrl('index'));
});

it('QuoteResource::backToIndexAction returns a gray arrow-left Action pointing at the index URL', function () {
    $action = QuoteResource::backToIndexAction();

    expect($action)->toBeInstanceOf(Action::class);
    expect($action->getName())->toBe('backToIndex');
    expect($action->getColor())->toBe('gray');
    expect($action->getIcon())->toBe('heroicon-o-arrow-left');
    expect($action->getUrl())->toBe(QuoteResource::getUrl('index'));
});

it('DealResource::backToIndexAction returns a gray arrow-left Action pointing at the index URL', function () {
    $action = DealResource::backToIndexAction();

    expect($action)->toBeInstanceOf(Action::class);
    expect($action->getName())->toBe('backToIndex');
    expect($action->getColor())->toBe('gray');
    expect($action->getIcon())->toBe('heroicon-o-arrow-left');
    expect($action->getUrl())->toBe(DealResource::getUrl('index'));
});

it('ViewLead header actions render in the documented order with Edit at gray color', function () {
    $actions = showActionOrderInvokeHeaderActions(ViewLead::class);

    $names = array_map(fn ($a) => $a->getName(), $actions);
    expect($names)->toBe([
        'backToIndex',
        'convertToDeal',
        'edit',
        'delete',
    ]);

    $edit = null;
    foreach ($actions as $action) {
        if ($action instanceof EditAction) {
            $edit = $action;

            break;
        }
    }
    expect($edit)->not->toBeNull();
    expect($edit->getColor())->toBe('gray');
});

it('ViewQuote header actions render in the documented order with Edit at gray color', function () {
    $actions = showActionOrderInvokeHeaderActions(ViewQuote::class);

    $names = array_map(fn ($a) => $a->getName(), $actions);
    // Send sits directly after Back-to-index — it is the action an operator
    // reaches for most often on a quote they are looking at.
    expect($names)->toBe([
        'backToIndex',
        'send',
        'accept',
        'reject',
        'unaccept',
        'unreject',
        'convertToOrder',
        'previewPortal',
        'downloadPdf',
        'edit',
        'delete',
    ]);

    $edit = null;
    foreach ($actions as $action) {
        if ($action instanceof EditAction) {
            $edit = $action;

            break;
        }
    }
    expect($edit)->not->toBeNull();
    expect($edit->getColor())->toBe('gray');
});

it('ViewDeal header actions render in the documented order with Edit at gray color', function () {
    $actions = showActionOrderInvokeHeaderActions(ViewDeal::class);

    $names = array_map(fn ($a) => $a->getName(), $actions);
    expect($names)->toBe([
        'backToIndex',
        'markWon',
        'markLost',
        'reopen',
        'edit',
        'delete',
    ]);

    $edit = null;
    foreach ($actions as $action) {
        if ($action instanceof EditAction) {
            $edit = $action;

            break;
        }
    }
    expect($edit)->not->toBeNull();
    expect($edit->getColor())->toBe('gray');
});

it('back_to_{leads,quotes,deals} translation keys exist and resolve in en, fr, and es', function () {
    $locales = ['en', 'fr', 'es'];
    $keys = ['back_to_leads', 'back_to_quotes', 'back_to_deals'];

    foreach ($locales as $locale) {
        app()->setLocale($locale);
        foreach ($keys as $key) {
            $translated = __('laravel-crm-filament::labels.actions.' . $key);
            expect($translated)->toBeString();
            expect($translated)->not->toBe('laravel-crm-filament::labels.actions.' . $key);
            expect(trim($translated))->not->toBe('');
        }
    }

    app()->setLocale('en');
});
