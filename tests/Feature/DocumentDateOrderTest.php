<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages\CreateInvoice;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\CreateQuote;

use function Pest\Livewire\livewire;

/**
 * An end date can't fall on an earlier day than its start date (core 2.7.0,
 * #106): an invoice's due date, a quote's expiry and a purchase order's
 * delivery date against the issue date. Days are compared, not times, and a
 * blank date on either side passes - these dates are optional.
 *
 * Only the end date's own error is asserted: the rest of each form is not
 * filled in, so it may have errors of its own.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    Setting::updateOrCreate(['name' => 'currency'], ['external_id' => (string) Str::uuid(), 'value' => 'USD']);
});

dataset('documents', [
    'invoice due date' => [CreateInvoice::class, 'issue_date', 'due_date'],
    'quote expiry' => [CreateQuote::class, 'issue_at', 'expire_at'],
    'purchase order delivery date' => [CreatePurchaseOrder::class, 'issue_date', 'delivery_date'],
]);

it('refuses an end date on an earlier day than the issue date', function (string $page, string $start, string $end) {
    livewire($page)
        ->fillForm([$start => '2026-03-10', $end => '2026-03-09'])
        ->call('create')
        ->assertHasFormErrors([$end])
        // Core's `date_not_before` message, ":Attribute can't be before the :date".
        ->assertSee('be before the ' . __('laravel-crm::lang.issue_date'));
})->with('documents');

it('accepts an end date on the same day as the issue date', function (string $page, string $start, string $end) {
    livewire($page)
        ->fillForm([$start => '2026-03-10', $end => '2026-03-10'])
        ->call('create')
        ->assertHasNoFormErrors([$end]);
})->with('documents');

it('accepts an end date after the issue date', function (string $page, string $start, string $end) {
    livewire($page)
        ->fillForm([$start => '2026-03-10', $end => '2026-04-10'])
        ->call('create')
        ->assertHasNoFormErrors([$end]);
})->with('documents');

it('accepts a blank date on either side', function (string $page, string $start, string $end, ?string $startValue, ?string $endValue) {
    livewire($page)
        ->fillForm([$start => $startValue, $end => $endValue])
        ->call('create')
        ->assertHasNoFormErrors([$end]);
})->with('documents')->with([
    'blank end' => ['2026-03-10', null],
    'blank issue' => [null, '2026-03-09'],
]);
