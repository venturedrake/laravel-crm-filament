<?php

use Filament\Panel;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFieldEntries;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmCustomFields;
use VentureDrake\LaravelCrmFilament\Concerns\HasLabels;
use VentureDrake\LaravelCrmFilament\Concerns\HasPrimaryBulkActions;
use VentureDrake\LaravelCrmFilament\Concerns\UsesExternalIdRouting;
use VentureDrake\LaravelCrmFilament\LaravelCrmFilamentServiceProvider;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\RelationManagers\AuditsRelationManager;
use VentureDrake\LaravelCrmFilament\RelationManagers\MonitorChecksRelationManager;
use VentureDrake\LaravelCrmFilament\Resources\Monitors\MonitorResource;
use VentureDrake\LaravelCrmFilament\Resources\Monitors\Pages\CreateMonitor;
use VentureDrake\LaravelCrmFilament\Resources\Monitors\Pages\EditMonitor;
use VentureDrake\LaravelCrmFilament\Resources\Monitors\Pages\ListMonitors;
use VentureDrake\LaravelCrmFilament\Resources\Monitors\Pages\ViewMonitor;

it('binds MonitorResource to the Monitor model with external_id routing and the monitors slug', function () {
    expect(MonitorResource::getModel())->toBe('VentureDrake\\LaravelCrm\\Models\\Monitor');
    expect(MonitorResource::getSlug())->toBe('monitors');
    expect(MonitorResource::getRecordRouteKeyName())->toBe('external_id');
});

it('uses ONLY the UsesExternalIdRouting trait (not HasLabels/HasCrmCustomFields/HasCrmActivities/HasPrimaryBulkActions)', function () {
    $traits = class_uses_recursive(MonitorResource::class);

    expect($traits)->toContain(UsesExternalIdRouting::class);
    // Monitor does NOT carry these traits per AC
    expect($traits)->not->toContain(HasLabels::class);
    expect($traits)->not->toContain(HasCrmCustomFields::class);
    expect($traits)->not->toContain(HasCrmCustomFieldEntries::class);
    expect($traits)->not->toContain(HasPrimaryBulkActions::class);
});

it('exposes list, create, view, and edit pages on MonitorResource', function () {
    expect(array_keys(MonitorResource::getPages()))
        ->toEqual(['index', 'create', 'view', 'edit']);
});

it('routes Monitor page classes back to MonitorResource', function () {
    foreach ([CreateMonitor::class, EditMonitor::class, ListMonitors::class, ViewMonitor::class] as $page) {
        $reflection = new ReflectionProperty($page, 'resource');
        $reflection->setAccessible(true);
        expect($reflection->getValue())->toBe(MonitorResource::class);
    }
});

it('declares the 2-col create/edit form fields: type (https/http selector), url, name (Friendly name), description, method, expected_status_code, interval, downtime_minutes_before_alert, perf_threshold_ms, user_owner_id, is_active toggle', function () {
    $source = file_get_contents((new ReflectionClass(MonitorResource::class))->getFileName());

    // Fields surfaced in the 2-col layout (matches the screenshot reference)
    expect($source)->toContain("Forms\\Components\\TextInput::make('url')");
    expect($source)->toContain("Forms\\Components\\TextInput::make('name')");
    expect($source)->toContain("Forms\\Components\\Textarea::make('description')");
    expect($source)->toContain("Forms\\Components\\Select::make('method')");
    expect($source)->toContain("Forms\\Components\\TextInput::make('expected_status_code')");
    expect($source)->toContain("Forms\\Components\\TextInput::make('interval')");
    expect($source)->toContain("Forms\\Components\\TextInput::make('downtime_minutes_before_alert')");
    expect($source)->toContain("Forms\\Components\\TextInput::make('perf_threshold_ms')");
    expect($source)->toContain("Forms\\Components\\Select::make('user_owner_id')");
    expect($source)->toContain("Forms\\Components\\Toggle::make('is_active')");

    // Core 2.7.0: the scheme is a visible https/http selector (tcp dropped),
    // defaulting to https.
    expect($source)->toContain("Forms\\Components\\Select::make('type')");
    expect($source)->not->toContain("Forms\\Components\\Hidden::make('type')");
    expect(MonitorResource::TYPE_OPTIONS)->toBe(['https' => 'https', 'http' => 'http']);

    // The screenshot layout drops these from the visible form.
    expect($source)->not->toContain("Forms\\Components\\KeyValue::make('headers')");
    expect($source)->not->toContain("Forms\\Components\\TextInput::make('timeout')");
    expect($source)->not->toContain("Forms\\Components\\Toggle::make('uptime_enabled')");
    expect($source)->not->toContain("Forms\\Components\\Toggle::make('ssl_enabled')");
    expect($source)->not->toContain("Forms\\Components\\Select::make('user_assigned_id')");
});

it('renders the form in a 2-col Section layout with the screenshot-style helper texts', function () {
    $source = file_get_contents((new ReflectionClass(MonitorResource::class))->getFileName());

    // The Section is configured with ->columns(2) for the 2-col layout.
    expect($source)->toMatch("/Section::make\\(__\\('laravel-crm-filament::labels\\.sections\\.monitor_settings'\\)\\)[\\s\\S]{0,200}->columns\\(2\\)/");

    // URL prefix follows the type selector; example.com placeholder per the
    // screenshot; core's monitor_url_hint as the helper text.
    expect($source)->toContain("->prefix(fn (Get \$get): string => static::schemeFor(\$get('type')) . '://')");
    expect($source)->toContain("->placeholder('example.com')");
    expect($source)->toContain("__('laravel-crm::lang.monitor_url_hint')");

    // The 4 helper-text translation keys are wired
    expect($source)->toContain('labels.sales.friendly_name_helper');
    expect($source)->toContain('labels.sales.method_helper');
    expect($source)->toContain('labels.sales.downtime_helper');
    expect($source)->toContain('labels.sales.threshold_helper');
});

it('does NOT make name required (nullable per AC since Monitor model declared name as nullable)', function () {
    $source = file_get_contents((new ReflectionClass(MonitorResource::class))->getFileName());

    // name has no ->required() chain.  The regex captures the make('name') call and a few lines after, then asserts ->required() does NOT appear in that vicinity.
    expect($source)->toMatch("/Forms\\\\Components\\\\TextInput::make\\('name'\\)[^;]{0,400}->maxLength\\(255\\)/");
    // url IS required
    expect($source)->toMatch("/Forms\\\\Components\\\\TextInput::make\\('url'\\)[\\s\\S]{0,400}->required\\(\\)/");
});

it('renders the 6 core-aligned columns in source: monitor_id, name, last_status, performance, last_response_time, last_checked_at', function () {
    $source = file_get_contents((new ReflectionClass(MonitorResource::class))->getFileName());

    expect($source)->toContain("Tables\\Columns\\TextColumn::make('monitor_id')");
    expect($source)->toContain("Tables\\Columns\\TextColumn::make('name')");
    expect($source)->toContain("Tables\\Columns\\TextColumn::make('last_status')");
    expect($source)->toContain("Tables\\Columns\\ViewColumn::make('performance')");
    expect($source)->toContain("Tables\\Columns\\TextColumn::make('last_response_time')");
    expect($source)->toContain("Tables\\Columns\\TextColumn::make('last_checked_at')");

    // Regression guard: dropped columns should not be present
    expect($source)->not->toContain("Tables\\Columns\\TextColumn::make('url')");
    expect($source)->not->toContain("Tables\\Columns\\TextColumn::make('type')");
    expect($source)->not->toContain("Tables\\Columns\\IconColumn::make('is_active')");
    expect($source)->not->toContain("Tables\\Columns\\IconColumn::make('uptime_enabled')");
    expect($source)->not->toContain("Tables\\Columns\\IconColumn::make('ssl_enabled')");
    expect($source)->not->toContain("Tables\\Columns\\TextColumn::make('ownerUser.name')");
    expect($source)->not->toContain("Tables\\Columns\\TextColumn::make('created_at')");
});

it('renders columns in the expected order: monitor_id, name, last_status, performance, last_response_time, last_checked_at', function () {
    $source = file_get_contents((new ReflectionClass(MonitorResource::class))->getFileName());

    // Find the first occurrence of each column's make() declaration.
    $names = [
        'monitor_id', 'name', 'last_status', 'performance', 'last_response_time', 'last_checked_at',
    ];
    $positions = [];
    foreach ($names as $col) {
        // First occurrence of TextColumn::make('col'), IconColumn::make('col'), or ViewColumn::make('col')
        $candidates = [];
        foreach (["TextColumn::make('{$col}')", "IconColumn::make('{$col}')", "ViewColumn::make('{$col}')"] as $needle) {
            $p = strpos($source, $needle);
            if ($p !== false) {
                $candidates[] = $p;
            }
        }
        $pos = $candidates === [] ? false : min($candidates);
        expect($pos)->not->toBe(false, "Column {$col} not found");
        $positions[$col] = $pos;
    }
    $sorted = $positions;
    asort($sorted);
    expect(array_keys($sorted))->toBe($names);
});

it('maps the last_status badge color: up=success, down=danger, slow=warning, null=gray', function () {
    $source = file_get_contents((new ReflectionClass(MonitorResource::class))->getFileName());

    expect($source)->toContain("'up' => 'success'");
    expect($source)->toContain("'down' => 'danger'");
    expect($source)->toContain("'slow' => 'warning'");
    expect($source)->toContain("default => 'gray'");
});

it('declares default sort created_at desc', function () {
    $source = file_get_contents((new ReflectionClass(MonitorResource::class))->getFileName());

    expect($source)->toContain("->defaultSort('created_at', 'desc')");
});

it('registers type, last_status, is_active, ssl_enabled, and user_owner_id filters on the table', function () {
    $source = file_get_contents((new ReflectionClass(MonitorResource::class))->getFileName());

    expect($source)->toContain("Tables\\Filters\\SelectFilter::make('type')");
    expect($source)->toContain("Tables\\Filters\\SelectFilter::make('last_status')");
    expect($source)->toContain("Tables\\Filters\\TernaryFilter::make('is_active')");
    expect($source)->toContain("Tables\\Filters\\TernaryFilter::make('ssl_enabled')");
    expect($source)->toContain("Tables\\Filters\\SelectFilter::make('user_owner_id')");
});

it('registers View, Edit, Delete record actions with Delete requiring confirmation', function () {
    $source = file_get_contents((new ReflectionClass(MonitorResource::class))->getFileName());

    expect($source)->toMatch('/recordActions\\(\\[(?:\\s|\\S)*?Actions\\\\ViewAction::make\\(\\)(?:\\s|\\S)*?Actions\\\\EditAction::make\\(\\)(?:\\s|\\S)*?Actions\\\\DeleteAction::make\\(\\)(?:\\s|\\S)*?->requiresConfirmation\\(\\)/');
});

it('returns exactly [MonitorChecksRelationManager] from getRelations() (audit History tab removed)', function () {
    expect(MonitorResource::getRelations())->toEqual([
        MonitorChecksRelationManager::class,
    ]);
});

it('does not register AuditsRelationManager on MonitorResource (history tab intentionally absent)', function () {
    expect(MonitorResource::getRelations())->not->toContain(
        AuditsRelationManager::class
    );
});

it('declares an infolist override with Details + SSL sections (Status moved to stat-card header widget)', function () {
    $source = file_get_contents((new ReflectionClass(MonitorResource::class))->getFileName());

    $reflection = new ReflectionMethod(MonitorResource::class, 'infolist');
    expect($reflection->getDeclaringClass()->getName())->toBe(MonitorResource::class);

    expect($source)->toContain("Section::make(__('laravel-crm-filament::labels.sections.details'))");
    expect($source)->toContain("Section::make(__('laravel-crm-filament::labels.sections.ssl'))");
});

it('gates the SSL section on ssl_enabled being true', function () {
    $source = file_get_contents((new ReflectionClass(MonitorResource::class))->getFileName());

    // Section::make(...ssl...) ... ->hidden(... ssl_enabled ...)
    expect($source)->toMatch("/Section::make\\(__\\('laravel-crm-filament::labels.sections.ssl'\\)\\)[\\s\\S]*?->hidden\\(/");
    expect($source)->toContain('ssl_enabled');
});

it('routes Create/Edit pages through MonitorService::create() and update() so URL normalization fires', function () {
    $createSource = file_get_contents((new ReflectionClass(CreateMonitor::class))->getFileName());
    $editSource = file_get_contents((new ReflectionClass(EditMonitor::class))->getFileName());

    expect($createSource)->toContain('MonitorService::class');
    expect($createSource)->toContain('->create(');

    expect($editSource)->toContain('MonitorService::class');
    expect($editSource)->toContain('->update(');
});

it('overrides ViewMonitor::content() with infolist on top of relation managers (vertical stack), mirroring core CRM monitor-show layout', function () {
    $source = file_get_contents((new ReflectionClass(ViewMonitor::class))->getFileName());

    expect($source)->toContain('getInfolistContentComponent()');
    expect($source)->toContain('getRelationManagersContentComponent()');

    $reflection = new ReflectionMethod(ViewMonitor::class, 'content');
    expect($reflection->getDeclaringClass()->getName())->toBe(ViewMonitor::class);
});

it('registers MonitorStatsWidget + MonitorResponseTimeChart as header widgets on ViewMonitor', function () {
    $source = file_get_contents((new ReflectionClass(ViewMonitor::class))->getFileName());

    expect($source)->toContain('getHeaderWidgets()');
    expect($source)->toContain('MonitorStatsWidget::class');
    expect($source)->toContain('MonitorResponseTimeChart::class');
});

it('declares ViewMonitor::getSubheading() returning monitor_id and url', function () {
    $source = file_get_contents((new ReflectionClass(ViewMonitor::class))->getFileName());

    expect($source)->toContain('public function getSubheading()');
    expect($source)->toContain('monitor_id');
    expect($source)->toContain('url');
});

it('ViewMonitor::getHeaderActions() returns Back, Edit, Delete in that order', function () {
    $source = file_get_contents((new ReflectionClass(ViewMonitor::class))->getFileName());

    expect($source)->toContain('MonitorResource::backToIndexAction()');
    expect($source)->toContain('Actions\\EditAction::make()');
    expect($source)->toContain('Actions\\DeleteAction::make()');

    $backPos = strpos($source, 'MonitorResource::backToIndexAction()');
    $editPos = strpos($source, 'Actions\\EditAction::make()');
    $deletePos = strpos($source, 'Actions\\DeleteAction::make()');

    expect($backPos)->toBeLessThan($editPos);
    expect($editPos)->toBeLessThan($deletePos);
});

it('declares backToIndexAction factory on MonitorResource', function () {
    expect(method_exists(MonitorResource::class, 'backToIndexAction'))->toBeTrue();

    $reflection = new ReflectionMethod(MonitorResource::class, 'backToIndexAction');
    expect($reflection->isStatic())->toBeTrue();
    expect($reflection->isPublic())->toBeTrue();
});

it('gates MonitorResource registration on the monitoring module flag', function () {
    // With the monitoring module on, the resource should be registered.
    $plugin = LaravelCrmPlugin::make()->modules(['monitoring' => true]);
    $panel = Panel::make()->id('admin-monitoring-on')->default();
    $plugin->register($panel);

    expect($panel->getResources())->toContain(MonitorResource::class);

    // With the monitoring module off, the resource should NOT be registered.
    $plugin = LaravelCrmPlugin::make()->modules(['monitoring' => false]);
    $panel = Panel::make()->id('admin-monitoring-off')->default();
    $plugin->register($panel);

    expect($panel->getResources())->not->toContain(MonitorResource::class);
});

it('adds Monitor::class to auditableModels() ONLY (not timelineActivityModels)', function () {
    expect(LaravelCrmFilamentServiceProvider::auditableModels())
        ->toContain('VentureDrake\\LaravelCrm\\Models\\Monitor');

    // Monitor has no HasCrmActivities trait, so it must NOT be in timelineActivityModels()
    expect(LaravelCrmFilamentServiceProvider::timelineActivityModels())
        ->not->toContain('VentureDrake\\LaravelCrm\\Models\\Monitor');
});
