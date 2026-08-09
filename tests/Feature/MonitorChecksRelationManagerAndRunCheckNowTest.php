<?php

use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Ramsey\Uuid\Uuid;
use VentureDrake\LaravelCrm\Models\Monitor;
use VentureDrake\LaravelCrmFilament\RelationManagers\MonitorChecksRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Monitors\MonitorResource;
use VentureDrake\LaravelCrmFilament\Resources\Monitors\Pages\ViewMonitor;

it('MonitorChecksRelationManager binds to the checks relation', function () {
    $reflection = new ReflectionProperty(MonitorChecksRelationManager::class, 'relationship');
    $reflection->setAccessible(true);
    expect($reflection->getValue())->toBe('checks');
});

it('MonitorChecksRelationManager extends Filament RelationManager', function () {
    expect(is_subclass_of(MonitorChecksRelationManager::class, RelationManager::class))->toBeTrue();
});

it('MonitorChecksRelationManager isReadOnly() returns true', function () {
    $rm = (new ReflectionClass(MonitorChecksRelationManager::class))->newInstanceWithoutConstructor();
    expect($rm->isReadOnly())->toBeTrue();
});

it('MonitorChecksRelationManager table has empty header/record/toolbar action arrays', function () {
    $rm = (new ReflectionClass(MonitorChecksRelationManager::class))->newInstanceWithoutConstructor();
    $table = $rm->table(Table::make($rm));

    expect($table->getHeaderActions())->toBe([]);
    expect($table->getRecordActions())->toBe([]);
    expect($table->getToolbarActions())->toBe([]);
});

it('MonitorChecksRelationManager exposes the AC-named columns: type, status, status_code, response_time, error_message, checked_at', function () {
    $rm = (new ReflectionClass(MonitorChecksRelationManager::class))->newInstanceWithoutConstructor();
    $table = $rm->table(Table::make($rm));

    $names = array_keys($table->getColumns());

    expect($names)->toEqual([
        'type',
        'status',
        'status_code',
        'response_time',
        'error_message',
        'checked_at',
    ]);
});

it('MonitorChecksRelationManager defaults to sort by checked_at desc', function () {
    $source = file_get_contents((new ReflectionClass(MonitorChecksRelationManager::class))->getFileName());

    expect($source)->toContain("->defaultSort('checked_at', 'desc')");
});

it('MonitorChecksRelationManager paginator options are [10, 25, 50, 100]', function () {
    $source = file_get_contents((new ReflectionClass(MonitorChecksRelationManager::class))->getFileName());

    expect($source)->toContain('->paginated([10, 25, 50, 100])');
});

it('MonitorChecksRelationManager renders type and status as badges and error_message with limit+tooltip', function () {
    $source = file_get_contents((new ReflectionClass(MonitorChecksRelationManager::class))->getFileName());

    expect($source)->toContain("Tables\\Columns\\TextColumn::make('type')");
    expect($source)->toContain("Tables\\Columns\\TextColumn::make('status')");
    expect($source)->toContain("Tables\\Columns\\TextColumn::make('error_message')");
    // type + status both have ->badge()
    expect(substr_count($source, '->badge()'))->toBeGreaterThanOrEqual(2);
    // error_message uses limit + tooltip
    expect($source)->toMatch("/Tables\\\\Columns\\\\TextColumn::make\\('error_message'\\)[\\s\\S]*?->limit\\(60\\)[\\s\\S]*?->tooltip\\(/");
    // checked_at uses since()
    expect($source)->toMatch("/Tables\\\\Columns\\\\TextColumn::make\\('checked_at'\\)[\\s\\S]*?->since\\(\\)/");
});

it('MonitorResource declares a public static runCheckNowAction factory returning an Action', function () {
    expect(method_exists(MonitorResource::class, 'runCheckNowAction'))->toBeTrue();

    $reflection = new ReflectionMethod(MonitorResource::class, 'runCheckNowAction');
    expect($reflection->isStatic())->toBeTrue();
    expect($reflection->isPublic())->toBeTrue();

    $action = MonitorResource::runCheckNowAction();
    expect($action)->toBeInstanceOf(Action::class);
    expect($action->getName())->toBe('runCheckNow');
    expect($action->isConfirmationRequired())->toBeTrue();
});

it('MonitorResource::runCheckNowAction source dispatches RunMonitorCheck via dispatchSync and fires a success Notification', function () {
    $source = file_get_contents((new ReflectionClass(MonitorResource::class))->getFileName());

    // Imports
    expect($source)->toContain('use VentureDrake\\LaravelCrm\\Jobs\\RunMonitorCheck;');
    expect($source)->toContain('use Filament\\Notifications\\Notification;');

    // Body of runCheckNowAction
    expect($source)->toContain('RunMonitorCheck::dispatchSync(');
    // Confirm we do NOT use plain dispatch (only literal '::dispatch(' without 'Sync')
    expect($source)->not->toMatch('/RunMonitorCheck::dispatch\(/');
    expect($source)->toContain('Notification::make()');
    expect($source)->toContain('->success()');
    expect($source)->toContain('->send()');
});

it('MonitorResource::runCheckNowAction body documents why dispatchSync is used', function () {
    $source = file_get_contents((new ReflectionClass(MonitorResource::class))->getFileName());

    // The comment should appear inside the runCheckNowAction body, before the dispatchSync call.
    $bodyStart = strpos($source, 'public static function runCheckNowAction(');
    expect($bodyStart)->not->toBeFalse();

    $body = substr($source, $bodyStart);
    $dispatchPos = strpos($body, 'RunMonitorCheck::dispatchSync(');
    expect($dispatchPos)->not->toBeFalse();

    $preamble = substr($body, 0, $dispatchPos);
    // Some kind of comment line preceding the dispatch
    expect($preamble)->toContain('// ');
    expect($preamble)->toContain('dispatchSync');
});

it('ViewMonitor header actions list is [backToIndex, runCheckNow, Edit, Delete] in that order', function () {
    $source = file_get_contents((new ReflectionClass(ViewMonitor::class))->getFileName());

    expect($source)->toContain('MonitorResource::backToIndexAction()');
    expect($source)->toContain('MonitorResource::runCheckNowAction()');
    expect($source)->toContain('Actions\\EditAction::make()');
    expect($source)->toContain('Actions\\DeleteAction::make()');

    $backPos = strpos($source, 'MonitorResource::backToIndexAction()');
    $runPos = strpos($source, 'MonitorResource::runCheckNowAction()');
    $editPos = strpos($source, 'Actions\\EditAction::make()');
    $deletePos = strpos($source, 'Actions\\DeleteAction::make()');

    expect($backPos)->toBeLessThan($runPos);
    expect($runPos)->toBeLessThan($editPos);
    expect($editPos)->toBeLessThan($deletePos);
});

it('runCheckNow dispatches RunMonitorCheck and creates a MonitorCheck row when invoked', function () {
    if (! class_exists('VentureDrake\\LaravelCrm\\Models\\Monitor')) {
        $this->markTestSkipped('Monitor model not present in vendor lock; integration test requires upstream model.');
    }

    if (! class_exists('VentureDrake\\LaravelCrm\\Jobs\\RunMonitorCheck')) {
        $this->markTestSkipped('RunMonitorCheck job not present in vendor lock.');
    }

    $monitorClass = 'VentureDrake\\LaravelCrm\\Models\\Monitor';
    $checkClass = 'VentureDrake\\LaravelCrm\\Models\\MonitorCheck';

    // Notification::fake(), not Queue::fake(): Bus\Dispatcher::dispatchSync()
    // routes a ShouldQueue job through the 'sync' connection, which QueueFake
    // swallows — so faking the queue here would stop the very job this test
    // exists to prove runs.
    NotificationFacade::fake();
    Http::fake([
        '*' => Http::response('OK', 200),
    ]);

    /** @var Monitor $monitor */
    $monitor = $monitorClass::create([
        'external_id' => (string) Uuid::uuid4(),
        'name' => 'Example',
        'type' => 'https',
        'method' => 'GET',
        'url' => 'https://example.com',
        'host' => 'example.com',
        'interval' => 5,
        'timeout' => 10,
        'expected_status_code' => 200,
        'is_active' => true,
        'uptime_enabled' => true,
        'ssl_enabled' => false,
    ]);

    expect($checkClass::query()->where('monitor_id', $monitor->id)->count())->toBe(0);

    // dispatchSync executes the job synchronously and does not enqueue, but uses the Bus.
    // The Bus pipeline calls Notification::send() which records on the faked Queue.
    $action = MonitorResource::runCheckNowAction();
    $action->record($monitor);
    $action->call();

    // The job ran synchronously and created at least one MonitorCheck row for this monitor.
    expect($checkClass::query()->where('monitor_id', $monitor->id)->count())->toBeGreaterThanOrEqual(1);
});
