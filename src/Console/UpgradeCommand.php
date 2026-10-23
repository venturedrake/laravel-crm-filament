<?php

namespace VentureDrake\LaravelCrmFilament\Console;

use FilesystemIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use VentureDrake\LaravelCrmFilament\LaravelCrmFilamentServiceProvider;

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
 * Thinner than core's because this package ships no compiled assets of its
 * own — no content-hashed bundle to prune. It does republish Filament's
 * (`filament:assets`): the panel renders with whatever copy of Filament's CSS
 * and JS sits in public/, and a host whose composer hooks never run
 * `filament:upgrade` keeps serving the copy from its first install after
 * Filament itself has moved on. Markup from the new release styled by the old
 * stylesheet breaks in ways that look like panel bugs — v5's old
 * `.fi-main-ctn { width: 100vw }` pushed every table's scrollbar off-screen.
 *
 * The rest is cache clearing, and `filament:optimize-clear` is the load-bearing
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

    /**
     * Republishes Filament's CSS and JS into public/, so the stylesheet matches
     * the installed Filament release.
     */
    public const FILAMENT_ASSETS_COMMAND = 'filament:assets';

    protected $signature = 'laravelcrm:filament-upgrade';

    protected $description = 'Republish Filament\'s assets and clear cached Filament components, config, routes and views. Safe to run on every composer install — touches no database';

    public function handle(): int
    {
        $this->info('Upgrading the Laravel CRM Filament panel...');

        $this->clearFilamentComponentCache();

        $this->publishFilamentAssets();

        $this->info('Clearing cached config, routes and views...');

        foreach (self::CACHE_COMMANDS as $command) {
            try {
                $this->callSilent($command);
            } catch (Throwable $e) {
                $this->warn("Could not run {$command}: " . $e->getMessage());
            }
        }

        $this->warnAboutDriftedViews();

        $this->info('Laravel CRM Filament panel caches are up to date.');
        $this->line('Run "php artisan laravelcrm:filament-update" to apply database migrations.');

        return self::SUCCESS;
    }

    /**
     * Report published panel views that no longer match the ones this release
     * ships.
     *
     * A published view is a frozen copy that the view finder prefers over the
     * package's own, so a blade left at an older contract keeps rendering after
     * the component behind it has stopped passing a variable — and the page
     * 500s on an undefined variable with nothing in the upgrade path having
     * said so. Core added the same check to `laravelcrm:upgrade` at 2.4.1 after
     * exactly that took out its demo install.
     *
     * Warn-only, and never a failure: this runs from a `post-autoload-dump`
     * hook on production boxes, where turning a cosmetic divergence into a
     * non-zero exit would break the deploy it was meant to protect.
     */
    protected function warnAboutDriftedViews(): void
    {
        try {
            $publishedRoot = resource_path('views/vendor/' . LaravelCrmFilamentServiceProvider::$viewNamespace);

            if (! is_dir($publishedRoot)) {
                return;
            }

            $shippedRoot = realpath(__DIR__ . '/../../resources/views');

            if ($shippedRoot === false) {
                return;
            }

            $drifted = [];

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($publishedRoot, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                $relative = ltrim(str_replace($publishedRoot, '', $file->getPathname()), '/');
                $shipped = $shippedRoot . '/' . $relative;

                if (! is_file($shipped)) {
                    $drifted[] = $relative . ' (this release no longer ships this view)';

                    continue;
                }

                // A file we cannot hash tells us nothing either way, and
                // md5_file() on it would emit a raw PHP warning into the middle
                // of the composer output and then return false — which compares
                // unequal and reports a drift we never established.
                if (! is_readable($file->getPathname())) {
                    continue;
                }

                if (md5_file($file->getPathname()) !== md5_file($shipped)) {
                    $drifted[] = $relative;
                }
            }

            if ($drifted === []) {
                return;
            }

            sort($drifted);
            $shown = array_slice($drifted, 0, 10);

            $this->warn(count($drifted) . ' published Laravel CRM Filament view(s) differ from the ones this release ships:');

            foreach ($shown as $path) {
                $this->warn('  - ' . $path);
            }

            if ($remaining = count($drifted) - count($shown)) {
                $this->warn("  ... and {$remaining} more.");
            }

            $this->warn('A published view is a frozen copy and is rendered instead of the package\'s own, so when a component stops passing a variable the frozen view still references it and the page 500s.');
            $this->warn('Run "php artisan vendor:publish --tag=laravel-crm-filament-views --force" to take this release\'s views, then re-apply any customisations on top of them.');
        } catch (Throwable $e) {
            // An unreadable resources directory is not a broken install.
            $this->warn('Could not check published Laravel CRM Filament views for drift: ' . $e->getMessage());
        }
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

    /**
     * Republish Filament's CSS and JS into public/.
     *
     * Probed for the same reason as clearFilamentComponentCache(). A failure
     * warns rather than fails: a read-only public/ on a build box is not a
     * broken install, and the stale copy it leaves still renders.
     */
    protected function publishFilamentAssets(): void
    {
        $application = $this->getApplication();

        if ($application === null || ! $application->has(self::FILAMENT_ASSETS_COMMAND)) {
            $this->info('Skipping ' . self::FILAMENT_ASSETS_COMMAND . ': the command is not registered.');

            return;
        }

        $this->info('Publishing Filament assets...');

        try {
            $this->callSilent(self::FILAMENT_ASSETS_COMMAND);
        } catch (Throwable $e) {
            $this->warn('Could not run ' . self::FILAMENT_ASSETS_COMMAND . ': ' . $e->getMessage());
            $this->warn('Run "php artisan ' . self::FILAMENT_ASSETS_COMMAND . '" manually, or the panel may render with a stylesheet from an older Filament release.');
        }
    }
}
