<?php

namespace VentureDrake\LaravelCrmFilament\Console\Concerns;

use VentureDrake\LaravelCrmFilament\Support\PanelSystemCheck;

/**
 * Record install-wide that this release's panel database work is done.
 *
 * Shared by both commands that finish that work: `laravelcrm:filament-install`,
 * which publishes and runs the migrations on a fresh host, and
 * `laravelcrm:filament-update`, which does the same again on every upgrade.
 * Both have to stamp. PanelSystemCheck treats a missing marker as behind, so an
 * install that migrated and never stamped raises "panel database update
 * required" over migrations it had just run.
 */
trait StampsPanelDbVersion
{
    /**
     * The panel version this code is, read off disk when the merged config
     * cannot answer.
     *
     * `config('laravel-crm-filament.version')` is populated by
     * `mergeConfigFrom()`, and that method is a documented no-op whenever the
     * host's configuration is cached — so on a box holding a `config:cache`
     * written before this release, the key is simply absent for the whole
     * process. Running `config:clear` partway through does not rescue it
     * either: that command deletes `bootstrap/cache/config.php` and leaves the
     * already-loaded repository exactly as it was.
     *
     * That is the host these commands run on during an upgrade, which is why
     * the constant falls back to the package's own config file. Reading it
     * directly cannot pick up a host's override, because the file is never
     * published — see config/package.php.
     */
    protected function panelCodeVersion(): string
    {
        $version = (string) config('laravel-crm-filament.version');

        if ($version !== '') {
            return $version;
        }

        $path = __DIR__ . '/../../../config/package.php';

        if (! is_file($path)) {
            return '';
        }

        $config = require $path;

        return is_array($config) ? (string) ($config['version'] ?? '') : '';
    }

    /**
     * Write the marker PanelSystemCheck reads back, and drop every cache that
     * answers the same question.
     *
     * `setInstallWide()`, not `set()`: a console command has no authenticated
     * user and therefore no team, so a plain `set()` writes a row that web
     * requests — which read Settings through BelongsToTeamsScope — cannot see,
     * leaving the panel reporting an update that has just been completed. It
     * also rewrites every row of the name, so per-team duplicates left by an
     * older install clear too.
     *
     * Called without a method_exists() probe, unlike the `--force` forwarding
     * in UpdateCommand: `setInstallWide()` arrived in laravel-crm 2.4.0, which
     * is the floor of this package's own composer constraint, so there is no
     * supported host without it. The `--force` probe stays because an option
     * the base command does not define aborts the run outright rather than
     * being ignored.
     *
     * @return bool Whether the marker was written.
     */
    protected function stampPanelDbVersion(): bool
    {
        $version = $this->panelCodeVersion();

        if ($version === '') {
            $this->warn('No laravel-crm-filament.version configured; skipping the ' . PanelSystemCheck::DB_VERSION_SETTING . ' marker.');

            return false;
        }

        $settings = app('laravel-crm.settings');

        $settings->setInstallWide(PanelSystemCheck::DB_VERSION_SETTING, $version);

        $settings->forgetCache();

        // Both checks, because both cache their answer for five minutes and the
        // operator's next page load is well inside that window.
        foreach (['laravel-crm.system-check', 'laravel-crm-filament.system-check'] as $binding) {
            if (app()->bound($binding)) {
                app($binding)->forgetCache();
            }
        }

        $this->info('Recorded ' . PanelSystemCheck::DB_VERSION_SETTING . ' = ' . $version . '.');

        return true;
    }
}
