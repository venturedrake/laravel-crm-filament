<?php

namespace VentureDrake\LaravelCrmFilament\Console;

use Illuminate\Console\Command;
use Throwable;

/**
 * The half of the panel upgrade that is safe to run unattended.
 *
 * This is what the host's `post-autoload-dump` composer hook fires (the
 * installer appends it — see InstallCommand::patchComposerScripts), so it runs
 * on every `composer install` and `composer update`, including on a production
 * box mid-deploy, as whatever user the build runs as, with no TTY. The same
 * three constraints laravelcrm:upgrade states apply verbatim:
 *
 *  - It never touches the database. No migrate, no seeders, not even a Setting
 *    read: during a build the database may be unreachable, mid-migration, or
 *    belong to a different release. Database work lives in
 *    laravelcrm:filament-update, which an operator runs explicitly.
 *  - It never prompts. There is nobody to answer.
 *  - It exits SUCCESS when there is nothing it can do, rather than failing the
 *    composer run.
 *
 * Thinner than core's because this package ships no compiled assets — there is
 * nothing to publish into public/ and no content-hashed bundle to prune. What
 * is left is cache clearing, and `filament:optimize-clear` is the load-bearing
 * one: Filament caches the panel's discovered components under
 * bootstrap/cache/filament/, so a host that cached them before the upgrade
 * simply does not see the new resources and pages.
 *
 * Deliberately does NOT call `laravelcrm:upgrade`. Core's own installer adds
 * its own `post-autoload-dump` hook, so on a host running both packages both
 * hooks fire; calling core's from here would double the work on every
 * `composer install`.
 */
class UpgradeCommand extends Command
{
    /**
     * The caches cleared on every run, in the order they are cleared.
     *
     * `config:clear` matters most of the three: new config keys arrive via
     * mergeConfigFrom — `laravel-crm-filament.version` among them — and a
     * stale `config:cache` from the previous release hides every one of them.
     *
     * @var array<int, string>
     */
    public const CACHE_COMMANDS = [
        'config:clear',
        'route:clear',
        'view:clear',
    ];

    /**
     * Filament's own cache-clearing command, cleared first because it is the
     * one whose staleness actually hides new panel components.
     */
    public const FILAMENT_CACHE_COMMAND = 'filament:optimize-clear';

    protected $signature = 'laravelcrm:filament-upgrade';

    protected $description = 'Clear cached Filament components, config, routes and views. Safe to run on every composer install — touches no database';

    public function handle(): int
    {
        $this->info('Upgrading the Laravel CRM Filament panel...');

        $this->clearFilamentComponentCache();

        $this->info('Clearing cached config, routes and views...');

        foreach (self::CACHE_COMMANDS as $command) {
            try {
                $this->callSilent($command);
            } catch (Throwable $e) {
                $this->warn("Could not run {$command}: " . $e->getMessage());
            }
        }

        $this->info('Laravel CRM Filament panel caches are up to date.');
        $this->line('Run "php artisan laravelcrm:filament-update" to apply database migrations.');

        return self::SUCCESS;
    }

    /**
     * Drop Filament's cached panel components.
     *
     * Probed rather than assumed: `filament:optimize-clear` arrived in
     * Filament v3, the command is not registered at all when the host has only
     * the form/table packages installed, and `Command::call()` on a name the
     * application does not know throws CommandNotFoundException — which would
     * fail the composer run this command exists to survive.
     */
    protected function clearFilamentComponentCache(): void
    {
        $application = $this->getApplication();

        if ($application === null || ! $application->has(self::FILAMENT_CACHE_COMMAND)) {
            $this->info('Skipping ' . self::FILAMENT_CACHE_COMMAND . ': the command is not registered.');

            return;
        }

        $this->info('Clearing cached Filament components...');

        try {
            $this->callSilent(self::FILAMENT_CACHE_COMMAND);
        } catch (Throwable $e) {
            // Not fatal, but say so loudly: a surviving component cache is the
            // difference between "the upgrade did nothing" and "the upgrade
            // worked but the panel does not show it".
            $this->warn('Could not run ' . self::FILAMENT_CACHE_COMMAND . ': ' . $e->getMessage());
            $this->warn('Run "php artisan ' . self::FILAMENT_CACHE_COMMAND . '" manually, or new CRM resources and pages may not appear.');
        }
    }
}
