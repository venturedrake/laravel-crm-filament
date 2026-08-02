<?php

use Filament\GlobalSearch\GlobalSearchResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Deal;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Models\Order;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrmFilament\Concerns\HasEncryptedGlobalSearch;
use VentureDrake\LaravelCrmFilament\Resources\Customers\CustomerResource;
use VentureDrake\LaravelCrmFilament\Resources\Deals\DealResource;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\InvoiceResource;
use VentureDrake\LaravelCrmFilament\Resources\Leads\LeadResource;
use VentureDrake\LaravelCrmFilament\Resources\Orders\OrderResource;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\OrganizationResource;
use VentureDrake\LaravelCrmFilament\Resources\People\PersonResource;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\QuoteResource;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

/**
 * Trait covered indirectly via PersonResource — the plugin's canonical
 * consumer of HasEncryptedGlobalSearch. Assertions target the two branches
 * of the override: the parent-delegate path when encryption is off and the
 * PHP-side decrypt-and-match path when encryption is on.
 */
beforeEach(function () {
    RoleSeeder::seed();

    $user = User::create([
        'name' => 'Search User',
        'email' => 'search-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ])->assignRole('Owner');

    $this->actingAs($user);
});

it('composes onto the resource as a trait', function () {
    expect(class_uses_recursive(PersonResource::class))->toContain(HasEncryptedGlobalSearch::class);
});

it('returns an empty collection for a blank search term when encryption is on', function () {
    config(['laravel-crm.encrypt_db_fields' => true]);

    $results = PersonResource::getGlobalSearchResults('   ');

    expect($results)->toBeInstanceOf(Collection::class);
    expect($results)->toHaveCount(0);
});

it('returns GlobalSearchResult rows for records whose accessor value matches the term', function () {
    config(['laravel-crm.encrypt_db_fields' => true]);

    Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
    ]);
    Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
    ]);

    $results = PersonResource::getGlobalSearchResults('lovel');

    expect($results)->toHaveCount(1);
    expect($results->first())->toBeInstanceOf(GlobalSearchResult::class);
    expect($results->first()->title)->toBe('Ada Lovelace');
});

it('returns no results when the term does not match any decrypted accessor value', function () {
    config(['laravel-crm.encrypt_db_fields' => true]);

    Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => 'Katherine',
        'last_name' => 'Johnson',
    ]);

    $results = PersonResource::getGlobalSearchResults('zzz-no-match');

    expect($results)->toHaveCount(0);
});

it('delegates to the parent implementation when encryption is off', function () {
    config(['laravel-crm.encrypt_db_fields' => false]);

    // Two people so that if the encrypted-branch accidentally runs it would
    // still find a record — the assertion targets return type, not identity.
    Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => 'Alan',
        'last_name' => 'Turing',
    ]);

    $results = PersonResource::getGlobalSearchResults('Turing');

    // Encryption-off branch returns whatever the parent gives us — for
    // Filament that's an Illuminate\Support\Collection of GlobalSearchResult.
    expect($results)->toBeInstanceOf(Collection::class);
});

// ----------------------------------------------------------------------------
// The trait is applied to every globally-searchable CRM resource, not just the
// contact-shaped ones. Lead / Deal / Quote / Order / Invoice all carry
// searchable columns that `laravel-crm.encrypt_db_fields` turns into cipher
// text, so a LIKE search against them silently matches nothing without this.
// ----------------------------------------------------------------------------

dataset('encryptedSearchResources', [
    'PersonResource' => [PersonResource::class],
    'OrganizationResource' => [OrganizationResource::class],
    'CustomerResource' => [CustomerResource::class],
    'LeadResource' => [LeadResource::class],
    'DealResource' => [DealResource::class],
    'QuoteResource' => [QuoteResource::class],
    'OrderResource' => [OrderResource::class],
    'InvoiceResource' => [InvoiceResource::class],
]);

it('composes the trait onto every globally-searchable CRM resource', function (string $resource) {
    expect(class_uses_recursive($resource))->toContain(HasEncryptedGlobalSearch::class);
})->with('encryptedSearchResources');

it('declares a crmEncryptedSearchAccessor covering the resource searchable attributes', function (string $resource) {
    $method = new ReflectionMethod($resource, 'crmEncryptedSearchAccessor');
    $method->setAccessible(true);

    $accessor = $method->invoke(null);
    expect($accessor)->toBeInstanceOf(Closure::class);

    // Every attribute the un-encrypted LIKE search uses must also appear in the
    // decrypt-and-match haystack, otherwise the two search paths disagree.
    $record = new stdClass;
    $values = [];

    foreach ($resource::getGloballySearchableAttributes() as $i => $attribute) {
        $values[$attribute] = 'zz' . $i . 'needle';
        $record->{$attribute} = $values[$attribute];
    }

    $haystack = (string) $accessor($record);

    foreach ($values as $value) {
        expect($haystack)->toContain($value);
    }
})->with('encryptedSearchResources');

it('finds a Lead by its encrypted title', function () {
    config(['laravel-crm.encrypt_db_fields' => true]);

    Lead::create(['external_id' => (string) Str::uuid(), 'title' => 'Encrypted renewal']);
    Lead::create(['external_id' => (string) Str::uuid(), 'title' => 'Unrelated work']);

    $results = LeadResource::getGlobalSearchResults('renewal');

    expect($results)->toHaveCount(1);
    expect($results->first())->toBeInstanceOf(GlobalSearchResult::class);
    expect($results->first()->title)->toBe('Encrypted renewal');
});

it('finds a Deal by its encrypted title', function () {
    config(['laravel-crm.encrypt_db_fields' => true]);

    Deal::create(['external_id' => (string) Str::uuid(), 'title' => 'Encrypted expansion']);
    Deal::create(['external_id' => (string) Str::uuid(), 'title' => 'Unrelated work']);

    $results = DealResource::getGlobalSearchResults('expansion');

    expect($results)->toHaveCount(1);
    expect($results->first()->title)->toBe('Encrypted expansion');
});

it('finds a Quote by its encrypted title', function () {
    config(['laravel-crm.encrypt_db_fields' => true]);

    Quote::create(['external_id' => (string) Str::uuid(), 'title' => 'Encrypted proposal']);
    Quote::create(['external_id' => (string) Str::uuid(), 'title' => 'Unrelated work']);

    $results = QuoteResource::getGlobalSearchResults('proposal');

    expect($results)->toHaveCount(1);
    expect($results->first()->title)->toBe('Encrypted proposal');
});

it('finds an Order by its encrypted reference', function () {
    config(['laravel-crm.encrypt_db_fields' => true]);

    // `order_id` is assigned by core CRM on create, so read it back rather
    // than asserting a literal.
    $order = Order::create(['external_id' => (string) Str::uuid(), 'reference' => 'Encrypted despatch']);
    Order::create(['external_id' => (string) Str::uuid(), 'reference' => 'Unrelated']);

    $results = OrderResource::getGlobalSearchResults('despatch');

    expect($results)->toHaveCount(1);
    expect($results->first()->title)->toBe((string) $order->fresh()->order_id);
});

it('finds an Invoice by its encrypted reference', function () {
    config(['laravel-crm.encrypt_db_fields' => true]);

    $invoice = Invoice::create(['external_id' => (string) Str::uuid(), 'reference' => 'Encrypted balance']);
    Invoice::create(['external_id' => (string) Str::uuid(), 'reference' => 'Unrelated']);

    $results = InvoiceResource::getGlobalSearchResults('balance');

    expect($results)->toHaveCount(1);
    expect($results->first()->title)->toBe((string) $invoice->fresh()->invoice_id);
});

it('returns nothing for a non-matching term on each added resource', function (string $resource) {
    config(['laravel-crm.encrypt_db_fields' => true]);

    expect($resource::getGlobalSearchResults('zzz-definitely-no-match'))->toHaveCount(0);
})->with('encryptedSearchResources');
