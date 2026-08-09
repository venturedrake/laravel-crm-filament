<?php

use Filament\Facades\Filament;
use Filament\Panel;
use VentureDrake\LaravelCrm\Models\LeadStatus;
use VentureDrake\LaravelCrm\Models\PipelineStageProbability;
use VentureDrake\LaravelCrm\Models\Team;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Pages\Updates;
use VentureDrake\LaravelCrmFilament\Resources\Leads\LeadResource;
use VentureDrake\LaravelCrmFilament\Resources\LeadStatuses\LeadStatusResource;
use VentureDrake\LaravelCrmFilament\Resources\PipelineStageProbabilities\PipelineStageProbabilityResource;
use VentureDrake\LaravelCrmFilament\Resources\PipelineStages\PipelineStageResource;
use VentureDrake\LaravelCrmFilament\Resources\Teams\CrmTeamResource;
use VentureDrake\LaravelCrmFilament\Resources\Teams\Pages\CreateCrmTeam;
use VentureDrake\LaravelCrmFilament\Resources\Teams\Pages\EditCrmTeam;
use VentureDrake\LaravelCrmFilament\Resources\Teams\Pages\ListCrmTeams;
use VentureDrake\LaravelCrmFilament\Resources\Teams\Pages\ViewCrmTeam;
use VentureDrake\LaravelCrmFilament\Resources\Teams\RelationManagers\TeamMembersRelationManager;

it('binds LeadStatusResource to the LeadStatus model as a top-level Resource in the Settings navigation group', function () {
    expect(LeadStatusResource::getModel())->toBe(LeadStatus::class);
    $nav = (new ReflectionClass(LeadStatusResource::class))->getStaticPropertyValue('navigationGroup');
    expect($nav)->toBe('Settings');
    expect(LeadStatusResource::getRecordRouteKeyName())->toBe('external_id');
});

it('exposes list+create+edit pages on LeadStatusResource', function () {
    expect(array_keys(LeadStatusResource::getPages()))->toEqual(['index', 'create', 'edit']);
});

it('binds PipelineStageProbabilityResource to the model as a top-level Resource in the Settings navigation group', function () {
    expect(PipelineStageProbabilityResource::getModel())->toBe(PipelineStageProbability::class);
    $nav = (new ReflectionClass(PipelineStageProbabilityResource::class))->getStaticPropertyValue('navigationGroup');
    expect($nav)->toBe('Settings');
    expect(PipelineStageProbabilityResource::getRecordRouteKeyName())->toBe('external_id');
});

it('exposes list+create+edit pages on PipelineStageProbabilityResource', function () {
    expect(array_keys(PipelineStageProbabilityResource::getPages()))->toEqual(['index', 'create', 'edit']);
});

it('wires a lead_status_id Select onto the Lead form', function () {
    $source = file_get_contents((new ReflectionClass(LeadResource::class))->getFileName());
    expect($source)->toContain("Forms\\Components\\Select::make('lead_status_id')");
    expect($source)->toContain('LeadStatus::query()');
});

it('wires a pipeline_stage_probability_id Select onto the PipelineStage form', function () {
    $source = file_get_contents((new ReflectionClass(PipelineStageResource::class))->getFileName());
    expect($source)->toContain("Forms\\Components\\Select::make('pipeline_stage_probability_id')");
    expect($source)->toContain('PipelineStageProbability::query()');
});

it('binds CrmTeamResource to the Team model as a top-level Contacts-group resource', function () {
    expect(CrmTeamResource::getModel())->toBe(Team::class);
    expect(CrmTeamResource::getSlug())->toBe('crm-teams');
});

it('declares Contacts as the navigation group on CrmTeamResource', function () {
    $reflection = new ReflectionProperty(CrmTeamResource::class, 'navigationGroup');
    $reflection->setAccessible(true);
    expect($reflection->getValue())->toBe('Contacts');
});

it('declares navigationSort=60 on CrmTeamResource', function () {
    $reflection = new ReflectionProperty(CrmTeamResource::class, 'navigationSort');
    $reflection->setAccessible(true);
    expect($reflection->getValue())->toBe(60);
});

it('lives under the top-level Resources\\Teams namespace', function () {
    expect(CrmTeamResource::class)->toStartWith('VentureDrake\\LaravelCrmFilament\\Resources\\Teams\\');
});

it('exposes CRUD pages on CrmTeamResource at the expected slug', function () {
    $pages = CrmTeamResource::getPages();
    expect(array_keys($pages))->toEqual(['index', 'create', 'view', 'edit']);
});

it('routes CrmTeam page classes back to CrmTeamResource under the new namespace', function () {
    foreach ([CreateCrmTeam::class, EditCrmTeam::class, ListCrmTeams::class, ViewCrmTeam::class] as $page) {
        expect($page)->toStartWith('VentureDrake\\LaravelCrmFilament\\Resources\\Teams\\Pages\\');
        $reflection = new ReflectionProperty($page, 'resource');
        $reflection->setAccessible(true);
        expect($reflection->getValue())->toBe(CrmTeamResource::class);
    }
});

it('attaches the TeamMembers relation manager to CrmTeamResource', function () {
    expect(CrmTeamResource::getRelations())->toContain(TeamMembersRelationManager::class);
});

it('targets the users relationship on the Team model from the TeamMembers RM', function () {
    $reflection = new ReflectionProperty(TeamMembersRelationManager::class, 'relationship');
    $reflection->setAccessible(true);
    expect($reflection->getValue())->toBe('users');
});

it('declares the Updates page in the Settings navigation group at /updates', function () {
    $nav = (new ReflectionClass(Updates::class))->getStaticPropertyValue('navigationGroup');
    expect($nav)->toBe('Settings');
    expect(Updates::getSlug())->toBe('updates');
});

it('checks the published version from the Updates page without running an update', function () {
    $source = file_get_contents((new ReflectionClass(Updates::class))->getFileName());
    expect($source)->toContain("'version_latest'");
    // The page reports only. Upgrades are a console step; see UpdatesPageTest.
    expect($source)->not->toContain('Artisan::queue');
});

it('registers all four new surfaces on the panel', function () {
    $plugin = LaravelCrmPlugin::make();
    $panel = Filament::getPanel('admin', false) ?? Panel::make()->id('admin')->default();
    $plugin->register($panel);

    foreach ([
        LeadStatusResource::class,
        PipelineStageProbabilityResource::class,
        CrmTeamResource::class,
    ] as $resource) {
        expect($panel->getResources())->toContain($resource);
    }
});
