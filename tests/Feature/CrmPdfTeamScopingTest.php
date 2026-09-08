<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Support\CrmPdf;

/**
 * PDF rendering on a `laravel-crm.teams` install.
 *
 * Core 2.4.2 pinned the settings service to the document's own team while
 * rendering, because the portal is anonymous: with no signed-in user
 * `BelongsToTeamsScope` disengages, the settings read is `pluck()`ed by name,
 * and whichever team's row sorted last supplied the organisation name, ABN,
 * contact block and logo for every tenant alike. The panel builds the same PDF
 * from the same service, so it pins too.
 *
 * The whole file is new because nothing exercised this: `tests/TestCase.php`
 * sets `laravel-crm.teams` to false, so the pinning branch never ran under test
 * and the null-team regression below shipped invisibly.
 */
function teamScopedInvoice(?int $teamId): Invoice
{
    return Invoice::create([
        'external_id' => (string) Str::uuid(),
        'invoice_id' => 'INV-' . ($teamId ?? 'orphan'),
        'currency' => 'USD',
        'issue_date' => now(),
        'due_date' => now()->addDays(30),
        'team_id' => $teamId,
    ]);
}

function seedTeamSettings(?int $teamId): void
{
    $settings = app('laravel-crm.settings');
    $settings->set('organization_name', 'Acme Inc', 'Organization name');
    $settings->set('tax_name', 'VAT', 'Tax name');
    $settings->set('pdf_contact_details', 'Acme, 1 Wharf St', 'Document contact details');
    $settings->forgetCache();

    Setting::query()->update(['team_id' => $teamId]);

    app('laravel-crm.settings')->forgetCache();
}

it('pins a team-owned document to its own team rather than whatever sorted last', function () {
    seedTeamSettings(1);
    $invoice = teamScopedInvoice(1);

    config(['laravel-crm.teams' => true]);
    app('laravel-crm.settings')->forgetCache();

    $data = CrmPdf::viewData('invoice', $invoice);

    expect($data['fromName'])->toBe('Acme Inc')
        ->and($data['taxName'])->toBe('VAT')
        ->and($data['contactDetails'])->toBe('Acme, 1 Wharf St');
});

/**
 * The regression this file was written for.
 *
 * `PortalTeam::forDocument()` answers null for a document that predates teams
 * or was created outside one, and `SettingService::forTeam(null)` is *not*
 * "read the default": it sets an override flag, drops the team scope, and then
 * filters to `global = 1` rows — or to `1 = 0` when the settings table is not
 * listed in `laravel-crm.model_with_global`. It never is: that config names
 * `settings` while the check compares `Setting::getTable()`, which carries the
 * `crm_` prefix.
 *
 * So pinning a null team resolved *no settings at all*, and the document
 * printed with no organisation name, no contact block, no logo, and a tax label
 * silently degrading to the literal "Tax" on an invoice that should read VAT.
 */
it('leaves a document with no team of its own unpinned, rather than resolving no settings', function () {
    seedTeamSettings(null);
    $invoice = teamScopedInvoice(null);

    $expected = CrmPdf::viewData('invoice', $invoice);

    config(['laravel-crm.teams' => true]);
    app('laravel-crm.settings')->forgetCache();

    $actual = CrmPdf::viewData('invoice', $invoice);

    expect($actual['fromName'])->toBe('Acme Inc')
        ->and($actual['taxName'])->toBe('VAT')
        ->and($actual['contactDetails'])->toBe('Acme, 1 Wharf St')
        // Turning teams on must not change what an untenanted document prints.
        ->and($actual)->toBe($expected);
});

it('renders end to end on a teams install and restores the shared service after', function () {
    seedTeamSettings(1);
    $invoice = teamScopedInvoice(1);

    config(['laravel-crm.teams' => true]);
    app('laravel-crm.settings')->forgetCache();

    $before = app('laravel-crm.settings');

    $path = CrmPdf::renderToDisk($invoice, 'invoice');

    expect(file_exists(storage_path($path)))->toBeTrue()
        ->and(substr((string) file_get_contents(storage_path($path)), 0, 4))->toBe('%PDF')
        // The binding is `scoped`, so a pin left in place would follow every
        // later settings read in the request.
        ->and(app('laravel-crm.settings'))->toBe($before)
        ->and(app('laravel-crm.settings')->get('organization_name'))->toBe('Acme Inc');
});
