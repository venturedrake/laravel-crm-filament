<?php

/*
|--------------------------------------------------------------------------
| Laravel CRM Filament Panel Version
|--------------------------------------------------------------------------
|
| Merged into the `laravel-crm-filament` config namespace from
| LaravelCrmFilamentServiceProvider::packageRegistered(), and deliberately
| NOT registered through Package::hasConfigFile(). A publishable copy would
| let a host publish this file and then pin the value, which would silently
| disable the whole db-version check that keys off it: PanelSystemCheck
| compares this against the `crm_filament_db_version` setting that
| `laravelcrm:filament-update` stamps, so an overridable version is an
| overridable "your database is up to date".
|
| Mirrors laravel-crm's own config/package.php, which does the same thing for
| the same reason. Bump it in the same commit as the CHANGELOG heading —
| VersionSyncTest asserts the two match.
|
*/

return [

    'version' => '1.1.0',

];
