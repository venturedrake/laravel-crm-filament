<?php

use Barryvdh\DomPDF\ServiceProvider as DomPdfServiceProvider;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use VentureDrake\LaravelCrm\Mail\SendQuote;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrm\Models\Pipeline;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\EditQuote;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\ListQuotes;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0's QuoteSend: the To field takes several addresses, each checked;
 * a transport failure is a danger notification with the modal kept open
 * rather than a 500; and only a quote that was sent moves from Draft to Sent.
 * The row action and the View / Edit page actions are one implementation;
 * the row and the Edit page are driven here (the View page's crm-view-tabs
 * blade trips Livewire's child-tag check in this harness).
 */
beforeEach(function () {
    $this->app->register(DomPdfServiceProvider::class);

    actingAsCrmAdmin();

    Setting::updateOrCreate(['name' => 'currency'], ['external_id' => (string) Str::uuid(), 'value' => 'USD']);
    app('laravel-crm.settings')->set('organization_name', 'Test Organization');
    app('laravel-crm.settings')->forgetCache();

    $pipeline = Pipeline::create([
        'external_id' => (string) Str::uuid(),
        'name' => 'Quote Pipeline',
        'model' => Quote::class,
    ]);

    $this->stages = collect(['Draft', 'Sent'])
        ->mapWithKeys(fn (string $name, int $order) => [$name => $pipeline->pipelineStages()->create([
            'external_id' => (string) Str::uuid(),
            'name' => $name,
            'order' => $order,
        ])]);

    $this->quote = Quote::create([
        'title' => 'To send',
        'currency' => 'USD',
        'pipeline_id' => $pipeline->id,
        'pipeline_stage_id' => $this->stages['Draft']->id,
    ])->fresh();
});

function sendQuoteFrom(string $where, Quote $quote, array $data)
{
    return match ($where) {
        'table' => livewire(ListQuotes::class)
            ->callAction(TestAction::make('send')->table($quote), $data),
        'edit' => livewire(EditQuote::class, ['record' => $quote->external_id])
            ->callAction('send', $data),
    };
}

it('mails every address in the list and marks the quote sent', function (string $where) {
    Mail::fake();

    sendQuoteFrom($where, $this->quote, [
        'to' => 'one@example.com; two@example.com, three@example.com',
        'subject' => 'Quote',
        'message' => 'Please find it attached.',
    ])->assertHasNoFormErrors()->assertNotified(ucfirst(__('laravel-crm::lang.quote_sent_successfully')));

    Mail::assertSent(SendQuote::class, fn (SendQuote $mail): bool => $mail->emailTo === [
        'one@example.com', 'two@example.com', 'three@example.com',
    ]);

    expect($this->quote->fresh()->pipelineStage->name)->toBe('Sent');
})->with(['table', 'edit']);

it('refuses an invalid address in the list as a field error', function (string $where) {
    Mail::fake();

    sendQuoteFrom($where, $this->quote, [
        'to' => 'one@example.com, not-an-address',
        'subject' => 'Quote',
        'message' => 'Please find it attached.',
    ])->assertHasFormErrors(['to']);

    Mail::assertNothingSent();
    expect($this->quote->fresh()->pipelineStage->name)->toBe('Draft');
})->with(['table', 'edit']);

it('shows a transport failure as a danger notification and leaves the quote unsent', function (string $where) {
    Mail::shouldReceive('send')->once()->andThrow(new TransportException('Connection refused'));

    sendQuoteFrom($where, $this->quote, [
        'to' => 'customer@example.com',
        'subject' => 'Quote',
        'message' => 'Please find it attached.',
    ])->assertNotified(ucfirst(__('laravel-crm::lang.email_could_not_be_sent', ['reason' => 'Connection refused'])));

    expect($this->quote->fresh()->pipelineStage->name)->toBe('Draft');
})->with(['table', 'edit']);

it('fills To from the contact\'s primary email and the subject from the quote number', function () {
    $person = Person::create([
        'external_id' => (string) Str::uuid(),
        'first_name' => 'Casey',
        'last_name' => 'Customer',
    ]);
    $person->emails()->create([
        'external_id' => (string) Str::uuid(),
        'address' => 'casey@example.com',
        'primary' => 1,
        'type' => 'work',
    ]);
    // No reference: core 2.7.0 fixed a subject that printed a blank one.
    $this->quote->update(['person_id' => $person->id, 'reference' => null]);

    livewire(EditQuote::class, ['record' => $this->quote->external_id])
        ->mountAction('send')
        ->assertSchemaStateSet([
            'to' => 'casey@example.com',
            'subject' => 'Quote ' . $this->quote->quote_id,
        ]);
});
