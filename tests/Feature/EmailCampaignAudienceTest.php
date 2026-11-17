<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use VentureDrake\LaravelCrm\Models\EmailCampaign;
use VentureDrake\LaravelCrm\Models\Label;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;
use VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\EmailCampaignResource;
use VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\Pages\CreateEmailCampaign;
use VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\Pages\EditEmailCampaign;
use VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\Pages\ViewEmailCampaign;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

/**
 * Core 2.7.0 email campaign audiences: who a campaign goes to, the
 * draft / send now / schedule step, and the refusals for an empty audience.
 */
beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Audience Tester',
        'email' => 'email-campaign-audience-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);

    Queue::fake();
});

function campaignAudiencePerson(string $first, ?string $address, bool $subscribed = true, array $attributes = []): Person
{
    $person = Person::create(['first_name' => $first, 'last_name' => 'Test', ...$attributes]);

    if ($address !== null) {
        $person->emails()->create(['address' => $address, 'primary' => true, 'subscribed' => $subscribed]);
    }

    return $person;
}

function campaignAudienceDraft(array $attributes = []): EmailCampaign
{
    return EmailCampaign::create([
        'name' => 'Spring sale',
        'subject' => 'Hello {first_name}',
        'body' => '<p>Body</p>',
        'status' => 'draft',
        'audience_type' => EmailCampaign::AUDIENCE_ALL,
        ...$attributes,
    ]);
}

/**
 * The Create/Save button lives in the page content's form-actions component.
 */
function campaignFormAction(string $name): TestAction
{
    return TestAction::make($name)->schemaComponent('form-actions', schema: 'content');
}

function campaignAudienceForm(array $overrides = []): array
{
    return [
        'name' => 'Spring sale',
        'subject' => 'Hello {first_name}',
        'body' => '<p>Body</p>',
        ...$overrides,
    ];
}

it('saves a labels audience as a draft by default', function () {
    $vip = Label::create(['name' => 'VIP']);

    livewire(CreateEmailCampaign::class)
        ->assertSchemaStateSet(['audience_type' => EmailCampaign::AUDIENCE_ALL, 'send_mode' => 'draft'])
        ->fillForm(campaignAudienceForm([
            'audience_type' => EmailCampaign::AUDIENCE_LABELS,
            'audience_ids' => [$vip->id],
        ]))
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified(ucfirst(__('laravel-crm::lang.campaign_saved_as_draft')));

    $campaign = EmailCampaign::query()->sole();

    expect($campaign->audience_type)->toBe(EmailCampaign::AUDIENCE_LABELS)
        ->and($campaign->audience_ids)->toBe([$vip->id])
        ->and($campaign->status)->toBe('draft')
        ->and($campaign->scheduled_at)->toBeNull();
});

it('saves people and organization audiences, and stores no ids for everyone', function (string $type) {
    $organization = Organization::create(['name' => 'Acme']);
    $person = campaignAudiencePerson('Ann', 'ann@example.com', attributes: ['organization_id' => $organization->id]);
    $id = $type === EmailCampaign::AUDIENCE_PEOPLE ? $person->id : $organization->id;

    livewire(CreateEmailCampaign::class)
        ->fillForm(campaignAudienceForm(['audience_type' => $type, 'audience_ids' => [$id]]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(EmailCampaign::query()->sole()->audience_ids)->toBe([$id]);
})->with([EmailCampaign::AUDIENCE_PEOPLE, EmailCampaign::AUDIENCE_ORGANIZATIONS]);

it('requires a selection for a filtered audience and stores no ids for everyone', function () {
    livewire(CreateEmailCampaign::class)
        ->fillForm(campaignAudienceForm(['audience_type' => EmailCampaign::AUDIENCE_LABELS, 'audience_ids' => []]))
        ->call('create')
        ->assertHasFormErrors(['audience_ids' => 'required']);

    livewire(CreateEmailCampaign::class)
        ->fillForm(campaignAudienceForm(['audience_type' => EmailCampaign::AUDIENCE_ALL]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(EmailCampaign::query()->sole()->audience_ids)->toBeNull();
});

it('clears the picked records when the audience type changes', function () {
    $vip = Label::create(['name' => 'VIP']);

    livewire(CreateEmailCampaign::class)
        ->fillForm(['audience_type' => EmailCampaign::AUDIENCE_LABELS, 'audience_ids' => [$vip->id]])
        ->fillForm(['audience_type' => EmailCampaign::AUDIENCE_PEOPLE])
        ->assertSchemaStateSet(['audience_ids' => []]);
});

it('searches people and organizations on the server and labels the picked ones', function () {
    campaignAudiencePerson('Annabel', 'annabel@example.com');
    $bob = campaignAudiencePerson('Bob', 'bob@example.com');
    Organization::create(['name' => 'Acme']);
    Organization::create(['name' => 'Globex']);

    expect(EmailCampaignResource::audienceSearchResults(EmailCampaign::AUDIENCE_PEOPLE, 'anna'))->toBe([Person::where('first_name', 'Annabel')->value('id') => 'Annabel Test'])
        ->and(array_values(EmailCampaignResource::audienceSearchResults(EmailCampaign::AUDIENCE_ORGANIZATIONS, 'glob')))->toBe(['Globex'])
        ->and(EmailCampaignResource::audienceOptions(EmailCampaign::AUDIENCE_PEOPLE, [$bob->id]))->toBe([$bob->id => 'Bob Test']);
});

it('counts the recipients the audience would reach', function () {
    $vip = Label::create(['name' => 'VIP']);
    campaignAudiencePerson('Ann', 'ann@example.com')->labels()->attach($vip);
    campaignAudiencePerson('Bob', 'bob@example.com')->labels()->attach($vip);
    campaignAudiencePerson('Cat', 'cat@example.com');
    campaignAudiencePerson('Dan', 'dan@example.com', subscribed: false)->labels()->attach($vip);

    expect(EmailCampaignResource::audienceCount(EmailCampaign::AUDIENCE_ALL, [$vip->id]))->toBe(3)
        ->and(EmailCampaignResource::audienceCount(EmailCampaign::AUDIENCE_LABELS, [$vip->id]))->toBe(2)
        ->and(EmailCampaignResource::audienceCount(EmailCampaign::AUDIENCE_LABELS, []))->toBe(0);

    livewire(CreateEmailCampaign::class)
        ->assertSee(ucfirst(__('laravel-crm::lang.recipients')));
});

it('refuses a non-draft save when nobody is in the audience', function (string $mode) {
    $empty = Label::create(['name' => 'Nobody']);

    livewire(CreateEmailCampaign::class)
        ->fillForm(campaignAudienceForm([
            'audience_type' => EmailCampaign::AUDIENCE_LABELS,
            'audience_ids' => [$empty->id],
            'send_mode' => $mode,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ]))
        ->call('create')
        ->assertNotified(ucfirst(__('laravel-crm::lang.no_recipients_in_audience')));

    expect(EmailCampaign::query()->count())->toBe(0);
})->with(['now', 'schedule']);

it('saves a draft even when nobody is in the audience', function () {
    $empty = Label::create(['name' => 'Nobody']);

    livewire(CreateEmailCampaign::class)
        ->fillForm(campaignAudienceForm([
            'audience_type' => EmailCampaign::AUDIENCE_LABELS,
            'audience_ids' => [$empty->id],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(EmailCampaign::query()->sole()->status)->toBe('draft');
});

it('confirms "send now" with the recipient count, then schedules it through the service', function () {
    campaignAudiencePerson('Ann', 'ann@example.com');
    campaignAudiencePerson('Bob', 'bob@example.com');

    livewire(CreateEmailCampaign::class)
        ->fillForm(campaignAudienceForm(['send_mode' => 'now']))
        ->mountAction(campaignFormAction('create'))
        ->assertActionMounted(campaignFormAction('create'))
        ->assertMountedActionModalSee(trans_choice('laravel-crm::lang.confirm_send_now', 2, ['count' => 2]))
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified(ucfirst(__('laravel-crm::lang.campaign_sending')));

    $campaign = EmailCampaign::query()->sole();

    expect($campaign->status)->toBe('scheduled')
        ->and($campaign->scheduled_at)->not->toBeNull()
        ->and($campaign->scheduled_at->lte(now()))->toBeTrue()
        ->and($campaign->total_recipients)->toBe(2)
        ->and($campaign->recipients()->count())->toBe(2);
});

it('still asks before sending when the form is submitted without the button', function () {
    campaignAudiencePerson('Ann', 'ann@example.com');

    // Enter in a field submits the form straight to create().
    $page = livewire(CreateEmailCampaign::class)
        ->fillForm(campaignAudienceForm(['send_mode' => 'now']))
        ->call('create')
        ->assertActionMounted(campaignFormAction('create'));

    expect(EmailCampaign::query()->count())->toBe(0);

    $page->callMountedAction();

    expect(EmailCampaign::query()->sole()->status)->toBe('scheduled');
});

it('does not open the confirmation for a draft save', function () {
    campaignAudiencePerson('Ann', 'ann@example.com');

    livewire(CreateEmailCampaign::class)
        ->fillForm(campaignAudienceForm())
        // Mounting alone saves: there is no modal waiting for a confirm.
        ->mountAction(campaignFormAction('create'))
        ->assertHasNoErrors();

    expect(EmailCampaign::query()->sole()->status)->toBe('draft');
});

it('schedules a campaign for the picked time', function () {
    campaignAudiencePerson('Ann', 'ann@example.com');
    $at = now()->addDays(2)->startOfMinute();

    livewire(CreateEmailCampaign::class)
        ->fillForm(campaignAudienceForm([
            'send_mode' => 'schedule',
            'scheduled_at' => $at->format('Y-m-d H:i:s'),
        ]))
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified(ucfirst(__('laravel-crm::lang.campaign_scheduled')));

    $campaign = EmailCampaign::query()->sole();

    expect($campaign->status)->toBe('scheduled')
        ->and($campaign->scheduled_at->equalTo($at))->toBeTrue()
        ->and($campaign->recipients()->count())->toBe(1);
});

it('refuses a schedule time in the past', function () {
    campaignAudiencePerson('Ann', 'ann@example.com');

    livewire(CreateEmailCampaign::class)
        ->fillForm(campaignAudienceForm([
            'send_mode' => 'schedule',
            'scheduled_at' => now()->subHour()->format('Y-m-d H:i:s'),
        ]))
        ->call('create')
        ->assertHasFormErrors(['scheduled_at']);

    expect(EmailCampaign::query()->count())->toBe(0);
});

it('loads the stored audience on edit and refuses an empty non-draft save there too', function () {
    $empty = Label::create(['name' => 'Nobody']);
    $campaign = campaignAudienceDraft([
        'audience_type' => EmailCampaign::AUDIENCE_LABELS,
        'audience_ids' => [$empty->id],
    ]);

    livewire(EditEmailCampaign::class, ['record' => $campaign->external_id])
        ->assertSchemaStateSet([
            'audience_type' => EmailCampaign::AUDIENCE_LABELS,
            'audience_ids' => [$empty->id],
            'send_mode' => 'draft',
        ])
        ->fillForm(['send_mode' => 'schedule', 'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s')])
        ->call('save')
        ->assertNotified(ucfirst(__('laravel-crm::lang.no_recipients_in_audience')));

    expect($campaign->fresh()->status)->toBe('draft');
});

it('sends now from the edit page behind the same confirmation', function () {
    campaignAudiencePerson('Ann', 'ann@example.com');
    $campaign = campaignAudienceDraft();

    livewire(EditEmailCampaign::class, ['record' => $campaign->external_id])
        ->fillForm(['send_mode' => 'now'])
        ->mountAction(campaignFormAction('save'))
        ->assertActionMounted(campaignFormAction('save'))
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertRedirect(EmailCampaignResource::getUrl('view', ['record' => $campaign]));

    expect($campaign->fresh()->status)->toBe('scheduled')
        ->and($campaign->recipients()->count())->toBe(1);
});

it('refuses the view page send-now action for an empty audience', function () {
    $empty = Label::create(['name' => 'Nobody']);
    campaignAudiencePerson('Ann', 'ann@example.com');
    $campaign = campaignAudienceDraft([
        'audience_type' => EmailCampaign::AUDIENCE_LABELS,
        'audience_ids' => [$empty->id],
    ]);

    livewire(ViewEmailCampaign::class, ['record' => $campaign->external_id])
        ->callAction('sendNow')
        ->assertNotified(ucfirst(__('laravel-crm::lang.no_recipients_in_audience')));

    expect($campaign->fresh()->status)->toBe('draft')
        ->and($campaign->recipients()->count())->toBe(0);
});

it('sends now from the view page to the audience only', function () {
    $vip = Label::create(['name' => 'VIP']);
    campaignAudiencePerson('Ann', 'ann@example.com')->labels()->attach($vip);
    campaignAudiencePerson('Bob', 'bob@example.com');
    $campaign = campaignAudienceDraft([
        'audience_type' => EmailCampaign::AUDIENCE_LABELS,
        'audience_ids' => [$vip->id],
    ]);

    livewire(ViewEmailCampaign::class, ['record' => $campaign->external_id])
        ->mountAction('sendNow')
        ->assertMountedActionModalSee(trans_choice('laravel-crm::lang.confirm_send_now', 1, ['count' => 1]))
        ->callMountedAction();

    expect($campaign->fresh()->status)->toBe('sending')
        ->and($campaign->recipients()->pluck('address')->all())->toBe(['ann@example.com']);
});

it('refuses the view page schedule action for an empty audience', function () {
    $empty = Label::create(['name' => 'Nobody']);
    $campaign = campaignAudienceDraft([
        'audience_type' => EmailCampaign::AUDIENCE_LABELS,
        'audience_ids' => [$empty->id],
    ]);

    livewire(ViewEmailCampaign::class, ['record' => $campaign->external_id])
        ->callAction('schedule', ['scheduled_at' => now()->addDay()->format('Y-m-d H:i:s')])
        ->assertNotified(ucfirst(__('laravel-crm::lang.no_recipients_in_audience')));

    expect($campaign->fresh()->status)->toBe('draft')
        ->and($campaign->fresh()->scheduled_at)->toBeNull();
});

it('describes the audience on the view page', function () {
    $vip = Label::create(['name' => 'VIP']);
    $newsletter = Label::create(['name' => 'Newsletter']);
    $campaign = campaignAudienceDraft([
        'audience_type' => EmailCampaign::AUDIENCE_LABELS,
        'audience_ids' => [$vip->id, $newsletter->id],
    ]);

    livewire(ViewEmailCampaign::class, ['record' => $campaign->external_id])
        ->assertSee(ucfirst(__('laravel-crm::lang.audience')))
        ->assertSee(ucfirst(__('laravel-crm::lang.labels')) . ': Newsletter, VIP');
});

it('previews the subject with its placeholders filled in', function () {
    $campaign = campaignAudienceDraft(['subject' => 'Hello {first_name}']);

    livewire(ViewEmailCampaign::class, ['record' => $campaign->external_id])
        ->mountAction('preview')
        ->assertMountedActionModalSee('Hello Jane');
});
