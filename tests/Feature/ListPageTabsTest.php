<?php

use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Livewire;
use VentureDrake\LaravelCrm\Models\EmailCampaign;
use VentureDrake\LaravelCrm\Models\SmsCampaign;
use VentureDrake\LaravelCrm\Models\Task;
use VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\Pages\ListEmailCampaigns;
use VentureDrake\LaravelCrmFilament\Resources\SmsCampaigns\Pages\ListSmsCampaigns;
use VentureDrake\LaravelCrmFilament\Resources\Tasks\Pages\ListTasks;
use VentureDrake\LaravelCrmFilament\Tests\RoleSeeder;
use VentureDrake\LaravelCrmFilament\Tests\Stubs\User;

use function Pest\Livewire\livewire;

dataset('listPagesWithExpectedTabs', [
    'Tasks' => [ListTasks::class, ['all', 'open', 'today', 'overdue', 'completed']],
    'EmailCampaigns' => [ListEmailCampaigns::class, ['all', 'draft', 'scheduled', 'sending', 'sent', 'failed']],
    'SmsCampaigns' => [ListSmsCampaigns::class, ['all', 'draft', 'scheduled', 'sending', 'sent', 'failed']],
]);

it('exposes the expected status tabs on each list page', function (string $page, array $expectedTabs) {
    $instance = new $page;
    $tabs = $instance->getTabs();

    expect(array_keys($tabs))->toBe($expectedTabs);
})->with('listPagesWithExpectedTabs');

// Filament passes the table query to a tab's modifyQueryUsing closure by
// parameter name ($query). A closure that names it anything else gets an
// empty Builder from the container instead, with no model, and the table
// then fails with "newQueryWithoutRelationships() on null" (#11).
dataset('statusTabQueries', [
    'Tasks open' => [ListTasks::class, Task::class, 'open', '"completed_at" is null'],
    'Tasks today' => [ListTasks::class, Task::class, 'today', 'strftime(\'%Y-%m-%d\', "due_at") = cast(? as text)'],
    'Tasks overdue' => [ListTasks::class, Task::class, 'overdue', 'strftime(\'%Y-%m-%d\', "due_at") < cast(? as text)'],
    'Tasks completed' => [ListTasks::class, Task::class, 'completed', '"completed_at" is not null'],
    'EmailCampaigns draft' => [ListEmailCampaigns::class, EmailCampaign::class, 'draft', '"status" = ?'],
    'EmailCampaigns scheduled' => [ListEmailCampaigns::class, EmailCampaign::class, 'scheduled', '"status" = ?'],
    'EmailCampaigns sending' => [ListEmailCampaigns::class, EmailCampaign::class, 'sending', '"status" = ?'],
    'EmailCampaigns sent' => [ListEmailCampaigns::class, EmailCampaign::class, 'sent', '"status" = ?'],
    'EmailCampaigns failed' => [ListEmailCampaigns::class, EmailCampaign::class, 'failed', '"status" = ?'],
    'SmsCampaigns draft' => [ListSmsCampaigns::class, SmsCampaign::class, 'draft', '"status" = ?'],
    'SmsCampaigns scheduled' => [ListSmsCampaigns::class, SmsCampaign::class, 'scheduled', '"status" = ?'],
    'SmsCampaigns sending' => [ListSmsCampaigns::class, SmsCampaign::class, 'sending', '"status" = ?'],
    'SmsCampaigns sent' => [ListSmsCampaigns::class, SmsCampaign::class, 'sent', '"status" = ?'],
    'SmsCampaigns failed' => [ListSmsCampaigns::class, SmsCampaign::class, 'failed', '"status" = ?'],
]);

it('filters the table query it is given rather than replacing it', function (string $page, string $model, string $tabKey, string $expectedWhere) {
    /** @var Tab $tab */
    $tab = (new $page)->getTabs()[$tabKey];

    /** @var class-string<Model> $model */
    $query = $model::query();
    $modified = $tab->modifyQuery($query);

    expect($modified)->toBeInstanceOf(Builder::class)
        ->and($modified->getModel())->toBeInstanceOf($model)
        ->and($modified->toSql())->toContain($expectedWhere);
})->with('statusTabQueries');

describe('status tabs on the Livewire list pages', function () {
    beforeEach(function () {
        RoleSeeder::seed();

        $this->user = User::create([
            'name' => 'Status Tab Tester',
            'email' => 'status-tab-tester' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);
        $this->user->assignRole('Admin');
        $this->actingAs($this->user);
    });

    function makeTask(string $name, array $attributes = []): Task
    {
        return Task::create([
            'external_id' => (string) Str::uuid(),
            'name' => $name,
            'user_owner_id' => auth()->id(),
            ...$attributes,
        ]);
    }

    it('filters tasks on each status tab', function () {
        $open = makeTask('QA Nov23 open', ['due_at' => now()->addDays(3)]);
        $today = makeTask('QA Nov23 today', ['due_at' => now()->startOfDay()->addHours(12)]);
        $overdue = makeTask('QA Nov23 overdue', ['due_at' => now()->subDays(2)]);
        $completed = makeTask('QA Nov23 completed', ['due_at' => now()->subDays(2), 'completed_at' => now()]);

        livewire(ListTasks::class)
            ->set('activeTab', 'open')
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$open, $today, $overdue])
            ->assertCanNotSeeTableRecords([$completed])
            ->set('activeTab', 'today')
            ->assertCanSeeTableRecords([$today])
            ->assertCanNotSeeTableRecords([$open, $overdue, $completed])
            ->set('activeTab', 'overdue')
            ->assertCanSeeTableRecords([$overdue])
            ->assertCanNotSeeTableRecords([$open, $today, $completed])
            ->set('activeTab', 'completed')
            ->assertCanSeeTableRecords([$completed])
            ->assertCanNotSeeTableRecords([$open, $today, $overdue]);
    });

    it('filters tasks on a status tab while searching', function () {
        $pending = makeTask('QA Nov23 pending');
        $completed = makeTask('QA Nov23 done', ['completed_at' => now()]);
        $otherCompleted = makeTask('Unrelated done', ['completed_at' => now()]);

        livewire(ListTasks::class)
            ->set('activeTab', 'completed')
            ->searchTable('QA Nov23')
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$completed])
            ->assertCanNotSeeTableRecords([$pending, $otherCompleted]);
    });

    it('filters tasks when the tab comes from the URL', function () {
        $pending = makeTask('QA Nov23 pending');
        $completed = makeTask('QA Nov23 done', ['completed_at' => now()]);

        livewire(ListTasks::class, ['activeTab' => 'completed'])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$completed])
            ->assertCanNotSeeTableRecords([$pending]);

        // `#[Url(as: 'tab')]` and `#[Url(as: 'search')]` on ListRecords: a
        // reload of /admin/tasks?tab=completed&search=… takes this path.
        Livewire::withQueryParams(['tab' => 'completed', 'search' => 'QA Nov23'])
            ->test(ListTasks::class)
            ->assertSuccessful()
            ->assertSet('activeTab', 'completed')
            ->assertCanSeeTableRecords([$completed])
            ->assertCanNotSeeTableRecords([$pending]);
    });

    it('filters email campaigns on the draft and sent tabs, with and without search', function () {
        $make = fn (string $name, string $status) => EmailCampaign::create([
            'external_id' => (string) Str::uuid(),
            'name' => $name,
            'subject' => $name,
            'body' => 'Body',
            'status' => $status,
        ]);

        $draft = $make('QA Nov23 draft', 'draft');
        $sent = $make('QA Nov23 sent', 'sent');
        $otherSent = $make('Unrelated sent', 'sent');

        livewire(ListEmailCampaigns::class)
            ->set('activeTab', 'draft')
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$draft])
            ->assertCanNotSeeTableRecords([$sent, $otherSent])
            ->set('activeTab', 'sent')
            ->assertCanSeeTableRecords([$sent, $otherSent])
            ->assertCanNotSeeTableRecords([$draft])
            ->searchTable('QA Nov23')
            ->assertCanSeeTableRecords([$sent])
            ->assertCanNotSeeTableRecords([$draft, $otherSent])
            ->set('activeTab', 'draft')
            ->assertCanSeeTableRecords([$draft])
            ->assertCanNotSeeTableRecords([$sent, $otherSent]);
    });

    it('filters SMS campaigns on the draft and sent tabs, with and without search', function () {
        $make = fn (string $name, string $status) => SmsCampaign::create([
            'external_id' => (string) Str::uuid(),
            'name' => $name,
            'body' => 'Body',
            'status' => $status,
        ]);

        $draft = $make('QA Nov23 draft', 'draft');
        $sent = $make('QA Nov23 sent', 'sent');
        $otherSent = $make('Unrelated sent', 'sent');

        livewire(ListSmsCampaigns::class)
            ->set('activeTab', 'draft')
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$draft])
            ->assertCanNotSeeTableRecords([$sent, $otherSent])
            ->set('activeTab', 'sent')
            ->assertCanSeeTableRecords([$sent, $otherSent])
            ->assertCanNotSeeTableRecords([$draft])
            ->searchTable('QA Nov23')
            ->assertCanSeeTableRecords([$sent])
            ->assertCanNotSeeTableRecords([$draft, $otherSent])
            ->set('activeTab', 'draft')
            ->assertCanSeeTableRecords([$draft])
            ->assertCanNotSeeTableRecords([$sent, $otherSent]);
    });
});
