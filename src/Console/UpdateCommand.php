<?php

namespace VentureDrake\LaravelCrmFilament\Console;

use Illuminate\Console\Command;
use Throwable;
use VentureDrake\LaravelCrmFilament\Console\Concerns\ProbesConsoleCommands;
use VentureDrake\LaravelCrmFilament\Console\Concerns\StampsPanelDbVersion;

/**
 * The half of the panel upgrade that touches the database.
 *
 * Split out from `laravelcrm:filament-upgrade` — which clears caches, runs from
 * the host's composer hook, and never opens a connection. This command runs
 * migrations, so it stays explicit: an operator (or a deploy script) runs it,
 * once, after the code is in place.
 *
 * It also runs `laravelcrm:update`, so a host upgrading the panel gets core's
 * schema brought forward in the same breath — one command, both packages, in
 * the right order. Core first is not a preference: this package's
 * `crm_invoice_payments` table carries foreign keys into core's tables, and
 * every resource the panel then boots reads core's schema.
 *
 * Every failure here is fatal, and says "has NOT been updated". Core 2.4 fixed
 * exactly the opposite behaviour — catching migration failures, downgrading
 * them to warnings and still printing success, which makes a broken upgrade
 * indistinguishable from a clean one in a deploy log and leaves the deploy
 * script's `&&` chain running happily on.
 */
class UpdateCommand extends Command
{
    use ProbesConsoleCommands;
    use StampsPanelDbVersion;

    /**
     * The base package command this one runs first.
     */
    public const CRM_UPDATE_COMMAND = 'laravelcrm:update';

    /**
     * The safe, database-free half, run first so the panel's component and
     * config caches are already gone by the time the migrations land.
     *
     * It does not rescue *this* process's config: `config:clear` deletes
     * `bootstrap/cache/config.php` and leaves the loaded repository untouched,
     * and `mergeConfigFrom()` was already skipped at register time on a host
     * whose config was cached. That is what panelCodeVersion() is for.
     */
    public const UPGRADE_COMMAND = 'laravelcrm:filament-upgrade';

    protected $signature = 'laravelcrm:filament-update
                           {--force : Run without confirmation, for deploy scripts}
                           {--skip-crm-update : Skip laravelcrm:update (assume the base package is already up to date)}';

    protected $description = 'Apply Laravel CRM Filament panel database migrations, and bring the base CRM package up to date first';

    public function handle(): int
    {
        if (! $this->confirmToProceed()) {
            return self::SUCCESS;
        }

        $this->info('Updating the Laravel CRM Filament panel...');

        if ($this->call(self::UPGRADE_COMMAND) !== self::SUCCESS) {
            $this->failWith(self::UPGRADE_COMMAND . ' failed.');

            return self::FAILURE;
        }

        if (! $this->updateBaseCrm()) {
            return self::FAILURE;
        }

        if (! $this->runPanelMigrations()) {
            return self::FAILURE;
        }

        // Only ever on the success path. PanelSystemCheck reads this back to
        // answer "is the panel code ahead of the database?", so stamping it
        // after a partial run would silence the very alert that would have told
        // the operator to re-run this command.
        $this->stampPanelDbVersion();

        $this->info('The Laravel CRM Filament panel is now updated.');

        return self::SUCCESS;
    }

    /**
     * Run `laravelcrm:update`, forwarding only what the installed version of it
     * actually accepts.
     *
     * `--force` landed on that command in laravel-crm 2.4.0; on 2.0–2.3 it does
     * not exist and passing it aborts with "The --force option does not exist".
     * `--no-interaction` is forwarded for the same reason the installer
     * forwards it: `Command::call()` builds a fresh, interactive input, so
     * without it core's production confirmation stalls a scripted deploy.
     */
    protected function updateBaseCrm(): bool
    {
        if ($this->option('skip-crm-update')) {
            $this->line('Skipping ' . self::CRM_UPDATE_COMMAND . ' (--skip-crm-update).');

            return true;
        }

        if (! $this->consoleCommandExists(self::CRM_UPDATE_COMMAND)) {
            $this->error('The ' . self::CRM_UPDATE_COMMAND . ' command is not registered — is venturedrake/laravel-crm installed?');
            $this->error('Run "php artisan ' . self::CRM_UPDATE_COMMAND . '" manually, or re-run this command with --skip-crm-update.');

            return $this->failWith('The base CRM package could not be updated.');
        }

        $this->info('Updating the base CRM package...');

        $arguments = [];

        if ($this->option('force')) {
            if ($this->commandSupportsOption(self::CRM_UPDATE_COMMAND, 'force')) {
                $arguments['--force'] = true;
            } else {
                $this->warn('The installed venturedrake/laravel-crm version does not support `--force` on ' . self::CRM_UPDATE_COMMAND . '; ignoring it. Upgrade to laravel-crm 2.4.0 or newer.');
            }
        }

        if (! $this->input->isInteractive()) {
            $arguments['--no-interaction'] = true;
        }

        try {
            $exitCode = $this->call(self::CRM_UPDATE_COMMAND, $arguments);
        } catch (Throwable $e) {
            return $this->failWith(self::CRM_UPDATE_COMMAND . ' failed: ' . $e->getMessage());
        }

        if ($exitCode !== self::SUCCESS) {
            return $this->failWith(self::CRM_UPDATE_COMMAND . ' failed.');
        }

        return true;
    }

    /**
     * Publish this package's migration stubs and run them.
     *
     * The forced re-publish is what lets a host pick up in-place fixes to a
     * stub it has already published — already-run migrations are unaffected by
     * the rewrite, because Laravel keys the migrations table by filename rather
     * than by content.
     *
     * `migrate` is fatal, not a warning. A failed ALTER leaves the schema
     * behind the code, and the panel reads that schema on every page.
     */
    protected function runPanelMigrations(): bool
    {
        $this->info('Publishing panel migrations...');

        try {
            $exitCode = $this->call('vendor:publish', [
                '--tag' => InstallCommand::MIGRATIONS_PUBLISH_TAG,
                '--force' => true,
            ]);
        } catch (Throwable $e) {
            return $this->failWith('Publishing panel migrations failed: ' . $e->getMessage());
        }

        if ($exitCode !== self::SUCCESS) {
            return $this->failWith('Publishing panel migrations failed.');
        }

        $this->info('Running migrations...');

        try {
            $exitCode = $this->call('migrate', ['--force' => true]);
        } catch (Throwable $e) {
            return $this->failWith('Migrations failed: ' . $e->getMessage());
        }

        if ($exitCode !== self::SUCCESS) {
            return $this->failWith('Migrations failed.');
        }

        return true;
    }

    /**
     * Report a fatal step and say, unambiguously, that nothing was updated.
     *
     * The sentence matters as much as the exit code: it is what an operator
     * greps a deploy log for.
     *
     * Named `failWith` rather than `fail` because Laravel 11's Command::fail()
     * throws a ManuallyFailedException — overriding it to return quietly would
     * be a trap for the next person to reach for the obvious name.
     *
     * Always returns false, so a step can `return $this->failWith(...)`.
     */
    protected function failWith(string $message): bool
    {
        $this->error($message);
        $this->error('The Laravel CRM Filament panel has NOT been updated.');

        return false;
    }

    /**
     * Whether to go ahead.
     *
     * Only ever asks on a production console with a real operator in front of
     * it, and defaults to yes so a deploy script that does not pass --force
     * still proceeds. Same shape as core's, deliberately.
     */
    protected function confirmToProceed(): bool
    {
        if ($this->option('force') || ! $this->input->isInteractive()) {
            return true;
        }

        if (! app()->environment('production')) {
            return true;
        }

        return $this->confirm('Application in production. Run Laravel CRM Filament panel database updates?', true);
    }
}
