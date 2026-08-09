<?php

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\Quote;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\Concerns\HasQuoteAcceptAction;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\Concerns\HasQuoteRejectAction;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\Concerns\HasQuoteUnacceptAction;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\EditQuote;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\ListQuotes;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\QuoteKanban;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\Pages\ViewQuote;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\QuoteResource;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    RoleSeeder::seed();

    $this->user = User::create([
        'name' => 'Quote Parity Tester',
        'email' => 'quote-parity-' . uniqid() . '@example.com',
        'password' => bcrypt('secret'),
    ]);
    $this->user->assignRole('Admin');
    $this->actingAs($this->user);
});

function quoteParityInvokeHeaderActions(string $page): array
{
    $instance = (new ReflectionClass($page))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($page, 'getHeaderActions');
    $method->setAccessible(true);

    return $method->invoke($instance);
}

it('table registers the parity column order', function () {
    $columns = livewire(ListQuotes::class)->instance()->getTable()->getColumns();
    $names = array_values(array_map(fn ($c) => $c->getName(), $columns));

    expect($names)->toBe([
        'created_at',
        'quote_id',
        'reference',
        'title',
        'labels.name',
        'person.name',
        'organization.name',
        'total',
        'issue_at',
        'expire_at',
        'pipelineStage.name',
        'ownerUser.name',
    ]);
});

it('created_at uses ->since() and labels.name uses ->limitList(3) and badge', function () {
    $columns = livewire(ListQuotes::class)->instance()->getTable()->getColumns();

    expect($columns['labels.name']->isBadge())->toBeTrue();

    $source = file_get_contents(__DIR__ . '/../../src/Resources/Quotes/QuoteResource.php');
    expect($source)->toContain("Tables\\Columns\\TextColumn::make('created_at')");
    expect($source)->toContain('->since()');
    expect($source)->toContain("Tables\\Columns\\TextColumn::make('labels.name')");
    expect($source)->toContain('->limitList(3)');
});

it('table registers owner, labels, pipeline_stage filters with the expected config', function () {
    $filters = livewire(ListQuotes::class)->instance()->getTable()->getFilters();

    $byName = [];
    foreach ($filters as $filter) {
        $byName[$filter->getName()] = $filter;
    }

    expect(array_keys($byName))->toContain('user_owner_id', 'labels', 'pipeline_stage_id');

    /** @var SelectFilter $owner */
    $owner = $byName['user_owner_id'];
    /** @var SelectFilter $labels */
    $labels = $byName['labels'];
    /** @var SelectFilter $stage */
    $stage = $byName['pipeline_stage_id'];

    expect($owner->isMultiple())->toBeTrue();
    expect($labels->isMultiple())->toBeTrue();
    expect($stage->isMultiple())->toBeFalse();

    expect($owner->getRelationshipName())->toBe('ownerUser');
    expect($labels->getRelationshipName())->toBe('labels');
});

it('row actions are buttons in View, Edit, Delete order', function () {
    $actions = array_values(livewire(ListQuotes::class)->instance()->getTable()->getRecordActions());

    // View / Edit / Delete close the row, after whatever document actions the
    // resource puts in front of them (download PDF, portal preview, …). The
    // ordering that matters is that the three CRUD actions come last, in this
    // order — not that they are the only ones.
    $actions = array_slice($actions, -3);

    expect($actions)->toHaveCount(3);
    expect($actions[0])->toBeInstanceOf(ViewAction::class);
    expect($actions[1])->toBeInstanceOf(EditAction::class);
    expect($actions[2])->toBeInstanceOf(DeleteAction::class);

    foreach ($actions as $action) {
        expect($action->isButton())->toBeTrue();
    }
});

it('listKanbanToggleActions reflects the current page via viewData', function () {
    $list = QuoteResource::listKanbanToggleActions('list');
    expect($list)->toHaveCount(1);
    expect($list[0]->getName())->toBe('viewToggle');
    expect($list[0]->getView())->toBe('laravel-crm-filament::components.list-kanban-toggle');
    expect($list[0]->getViewData())->toMatchArray([
        'current' => 'list',
        'listUrl' => QuoteResource::getUrl('index'),
        'kanbanUrl' => QuoteResource::getUrl('kanban'),
    ]);

    $kanban = QuoteResource::listKanbanToggleActions('kanban');
    expect($kanban[0]->getViewData()['current'])->toBe('kanban');
});

it('ListQuotes and QuoteKanban headers expose the viewToggle and a Create action', function () {
    $listActions = quoteParityInvokeHeaderActions(ListQuotes::class);
    expect($listActions)->toHaveCount(2);
    expect($listActions[0]->getName())->toBe('viewToggle');
    expect($listActions[0]->getViewData()['current'])->toBe('list');
    expect($listActions[1])->toBeInstanceOf(CreateAction::class);

    $kanbanActions = quoteParityInvokeHeaderActions(QuoteKanban::class);
    expect($kanbanActions)->toHaveCount(2);
    expect($kanbanActions[0]->getName())->toBe('viewToggle');
    expect($kanbanActions[0]->getViewData()['current'])->toBe('kanban');
    expect($kanbanActions[1])->toBeInstanceOf(CreateAction::class);
    expect($kanbanActions[1]->getUrl())->toBe(QuoteResource::getUrl('create'));
});

it('ListQuotes no longer exposes tab entries', function () {
    $instance = new ListQuotes;
    expect($instance->getTabs())->toBe([]);
});

it('accept/reject/unaccept/unreject factories are visible only in their state', function () {
    $open = Quote::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Pending quote',
    ]);
    $accepted = Quote::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Accepted quote',
        'accepted_at' => now()->subDay(),
    ]);
    $rejected = Quote::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Rejected quote',
        'rejected_at' => now()->subDay(),
    ]);

    $accept = QuoteResource::acceptAction();
    $reject = QuoteResource::rejectAction();
    $unaccept = QuoteResource::unacceptAction();
    $unreject = QuoteResource::unrejectAction();

    expect($accept)->toBeInstanceOf(Action::class);
    expect($reject)->toBeInstanceOf(Action::class);
    expect($unaccept)->toBeInstanceOf(Action::class);
    expect($unreject)->toBeInstanceOf(Action::class);

    expect($accept->getName())->toBe('accept');
    expect($reject->getName())->toBe('reject');
    expect($unaccept->getName())->toBe('unaccept');
    expect($unreject->getName())->toBe('unreject');

    // Accept hidden if already accepted; reject hidden if already rejected
    $accept->record($open);
    expect($accept->isVisible())->toBeTrue();
    $accept->record($accepted);
    expect($accept->isVisible())->toBeFalse();

    $reject->record($open);
    expect($reject->isVisible())->toBeTrue();
    $reject->record($rejected);
    expect($reject->isVisible())->toBeFalse();

    // Unaccept visible only when accepted; Unreject visible only when rejected
    $unaccept->record($open);
    expect($unaccept->isVisible())->toBeFalse();
    $unaccept->record($accepted);
    expect($unaccept->isVisible())->toBeTrue();
    $unaccept->record($rejected);
    expect($unaccept->isVisible())->toBeFalse();

    $unreject->record($open);
    expect($unreject->isVisible())->toBeFalse();
    $unreject->record($rejected);
    expect($unreject->isVisible())->toBeTrue();
    $unreject->record($accepted);
    expect($unreject->isVisible())->toBeFalse();
});

it('acceptAction stamps accepted_at and clears rejected_at', function () {
    $quote = Quote::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Q1',
        'rejected_at' => now()->subDay(),
    ]);

    $action = QuoteResource::acceptAction();
    $action->record($quote);
    $action->call();

    $quote->refresh();
    expect($quote->accepted_at)->not->toBeNull();
    expect($quote->rejected_at)->toBeNull();
});

it('rejectAction stamps rejected_at and clears accepted_at', function () {
    $quote = Quote::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Q2',
        'accepted_at' => now()->subDay(),
    ]);

    $action = QuoteResource::rejectAction();
    $action->record($quote);
    $action->call();

    $quote->refresh();
    expect($quote->rejected_at)->not->toBeNull();
    expect($quote->accepted_at)->toBeNull();
});

it('unacceptAction clears accepted_at', function () {
    $quote = Quote::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Q3',
        'accepted_at' => now()->subDay(),
    ]);

    $action = QuoteResource::unacceptAction();
    $action->record($quote);
    $action->call();

    $quote->refresh();
    expect($quote->accepted_at)->toBeNull();
});

it('unrejectAction clears rejected_at', function () {
    $quote = Quote::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Q4',
        'rejected_at' => now()->subDay(),
    ]);

    $action = QuoteResource::unrejectAction();
    $action->record($quote);
    $action->call();

    $quote->refresh();
    expect($quote->rejected_at)->toBeNull();
});

it('ViewQuote and EditQuote headers include accept/reject and the unaccept pair; concerns applied', function () {
    $viewActions = quoteParityInvokeHeaderActions(ViewQuote::class);
    $viewNames = array_map(fn ($a) => $a->getName(), $viewActions);
    expect($viewNames)->toContain('accept', 'reject', 'unaccept', 'unreject');

    $editActions = quoteParityInvokeHeaderActions(EditQuote::class);
    $editNames = array_map(fn ($a) => $a->getName(), $editActions);
    expect($editNames)->toContain('accept', 'reject', 'unaccept', 'unreject');

    $viewUses = class_uses_recursive(ViewQuote::class);
    expect($viewUses)->toContain(HasQuoteAcceptAction::class);
    expect($viewUses)->toContain(HasQuoteRejectAction::class);
    expect($viewUses)->toContain(HasQuoteUnacceptAction::class);

    $editUses = class_uses_recursive(EditQuote::class);
    expect($editUses)->toContain(HasQuoteAcceptAction::class);
    expect($editUses)->toContain(HasQuoteRejectAction::class);
    expect($editUses)->toContain(HasQuoteUnacceptAction::class);
});

it('ConvertToOrder action is gated on accepted_at being non-null', function () {
    $page = new ViewQuote;
    $method = new ReflectionMethod(ViewQuote::class, 'quoteConvertToOrderAction');
    $method->setAccessible(true);
    $action = $method->invoke($page);

    $open = Quote::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Open',
    ]);
    $accepted = Quote::create([
        'external_id' => (string) Str::uuid(),
        'title' => 'Accepted',
        'accepted_at' => now(),
    ]);

    $action->record($open);
    expect($action->isVisible())->toBeFalse();

    $action->record($accepted);
    expect($action->isVisible())->toBeTrue();
});

it('translation keys for accept, reject, unaccept, unreject exist', function () {
    expect(trans('laravel-crm-filament::labels.actions.accept'))->toBe('Accept');
    expect(trans('laravel-crm-filament::labels.actions.reject'))->toBe('Reject');
    expect(trans('laravel-crm-filament::labels.actions.unaccept'))->toBe('Unaccept');
    expect(trans('laravel-crm-filament::labels.actions.unreject'))->toBe('Unreject');
});
