<?php

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Core 2.6.0 refuses `migrate` and `laravelcrm:update` on a v1 database, but
 * only when they are the command typed. The panel's install and update
 * commands run both nested, so they run core's check themselves.
 */
class GuardSpyLaravelCrmUpdate extends Command
{
    public static bool $ran = false;

    protected $signature = 'laravelcrm:update {--force}';

    public function handle(): int
    {
        self::$ran = true;

        return self::SUCCESS;
    }
}

beforeEach(function () {
    GuardSpyLaravelCrmUpdate::$ran = false;

    Schema::create(config('laravel-crm.db_table_prefix') . 'organisations', function (Blueprint $table) {
        $table->bigIncrements('id');
    });
});

afterEach(function () {
    Schema::dropIfExists(config('laravel-crm.db_table_prefix') . 'organisations');
});

it('refuses laravelcrm:filament-update on a v1 database before touching anything', function () {
    Artisan::registerCommand(new GuardSpyLaravelCrmUpdate);

    $this->artisan('laravelcrm:filament-update', ['--force' => true])
        ->expectsOutputToContain('laravelcrm:v2')
        ->expectsOutputToContain('has NOT been updated')
        ->assertFailed();

    expect(GuardSpyLaravelCrmUpdate::$ran)->toBeFalse();
});

it('refuses laravelcrm:filament-install on a v1 database', function () {
    $this->artisan('laravelcrm:filament-install', ['--mode' => 'crm', '--skip-crm-install' => true])
        ->expectsOutputToContain('laravelcrm:v2')
        ->assertFailed();
});
