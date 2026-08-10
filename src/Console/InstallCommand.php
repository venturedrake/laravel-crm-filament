<?php

namespace VentureDrake\LaravelCrmFilament\Console;

use Filament\Panel;
use Filament\PanelProvider;
use Filament\PanelRegistry;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use ReflectionClass;
use stdClass;
use Throwable;
use VentureDrake\LaravelCrmFilament\Console\Concerns\ProbesConsoleCommands;
use VentureDrake\LaravelCrmFilament\Console\Concerns\StampsPanelDbVersion;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Support\TenancyGuard;

class InstallCommand extends Command
{
    use ProbesConsoleCommands;
    use StampsPanelDbVersion;

    /**
     * The composer script line appended to the host's `post-autoload-dump`.
     */
    public const COMPOSER_HOOK_ENTRY = '@php artisan laravelcrm:filament-upgrade --ansi';

    /**
     * Publish tag for the plugin's migrations. Spatie's PackageServiceProvider
     * registers it as `{shortName}-migrations`, and shortName() strips the
     * `laravel-` prefix from `laravel-crm-filament`.
     */
    public const MIGRATIONS_PUBLISH_TAG = 'crm-filament-migrations';

    protected $signature = 'laravelcrm:filament-install
        {--mode= : Install mode: crm (standalone panel) or inject (into an existing panel).}
        {--panel= : Target panel id when using --mode=inject.}
        {--force : Overwrite an existing CrmPanelProvider.}
        {--modules= : Comma separated module list forwarded to laravelcrm:install (requires laravel-crm 2.3.0+).}
        {--skip-crm-install : Skip the venturedrake/laravel-crm install check (assume it is already installed).}
        {--allow-teams : Install anyway when laravel-crm.teams is on. The panel is not tenant-aware; see the README.}
        {--no-composer-hook : Do not add laravelcrm:filament-upgrade to the host composer.json post-autoload-dump scripts.}';

    protected $description = 'Install the Laravel CRM Filament panel (publishes CrmPanelProvider at app/Providers/Filament/CrmPanelProvider.php).';

    public function handle(Filesystem $files): int
    {
        if (! $this->ensureTenancySupported()) {
            return self::FAILURE;
        }

        if (! $this->ensureLaravelCrmInstalled()) {
            return self::FAILURE;
        }

        $mode = $this->option('mode');
        $panelId = $this->option('panel');

        if ($mode === null) {
            [$mode, $panelId] = $this->resolveModeAndPanel($panelId);
        } elseif ($mode === 'inject' && $panelId === null) {
            $panelId = $this->promptForTargetPanel();
        }

        if ($mode === 'inject') {
            return $this->installInjectMode($files, $panelId);
        }

        return $this->installCrmMode($files);
    }

    /**
     * Refuse to install onto a multi-tenant CRM.
     *
     * The panel is not tenant-aware — its role, permission and user queries are
     * not team-scoped — and half-scoping is worse than not scoping, because it
     * looks tenanted right up to the row that isn't. Install is the one moment
     * that is interactive and trivially reversible, so it is the one place a
     * refusal belongs; at runtime the plugin warns instead. See TenancyGuard.
     */
    private function ensureTenancySupported(): bool
    {
        if (! TenancyGuard::isEnabled() || $this->option('allow-teams')) {
            return true;
        }

        $this->components->error(TenancyGuard::message());
        $this->components->warn('Pass --allow-teams to install anyway.');

        return false;
    }

    private function ensureLaravelCrmInstalled(): bool
    {
        if ($this->option('skip-crm-install')) {
            return true;
        }

        if ($this->laravelCrmIsInstalled()) {
            return true;
        }

        $this->warn('The venturedrake/laravel-crm package does not appear to be installed yet.');
        $this->line('The Filament panel needs the CRM package config, migrations, and seed data in place before it can run.');

        $confirmed = $this->confirm('Run `php artisan laravelcrm:install` now?', true);

        if (! $confirmed) {
            $this->error('Aborting. Run `php artisan laravelcrm:install` manually, then re-run this command.');

            return false;
        }

        $exitCode = $this->callLaravelCrmInstall();

        if ($exitCode !== 0) {
            $this->error('`laravelcrm:install` exited with a non-zero status. Fix the reported issue and re-run this command.');

            return false;
        }

        return true;
    }

    /**
     * Run the base package installer, forwarding `--modules` and
     * `--no-interaction`. laravel-crm 2.3.0 added an interactive module
     * prompt: `Command::call()` builds a fresh interactive input, so without
     * forwarding `--no-interaction` that prompt stalls scripted installs.
     * Older base versions have no `modules` option, so probe the definition
     * first — see ProbesConsoleCommands — and warn rather than fail with an
     * "option does not exist" error.
     */
    private function callLaravelCrmInstall(): int
    {
        $arguments = [];

        $modules = $this->option('modules');

        if (is_string($modules) && $modules !== '') {
            if ($this->commandSupportsOption('laravelcrm:install', 'modules')) {
                $arguments['--modules'] = $modules;
            } else {
                $this->warn('The installed venturedrake/laravel-crm version does not support `--modules`; ignoring it. Upgrade to laravel-crm 2.3.0 or newer to choose modules during install.');
            }
        }

        if (! $this->input->isInteractive()) {
            $arguments['--no-interaction'] = true;
        }

        return $this->call('laravelcrm:install', $arguments);
    }

    private function laravelCrmIsInstalled(): bool
    {
        return file_exists(config_path('laravel-crm.php'));
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function resolveModeAndPanel(?string $panelId): array
    {
        $panels = $this->detectPanels();

        if ($panels === []) {
            return ['crm', $panelId];
        }

        $isCrmOnly = count($panels) === 1 && $panels[0]['id'] === 'crm';

        if ($isCrmOnly) {
            return ['crm', $panelId];
        }

        $this->line('Detected Filament panels:');
        $this->table(
            ['ID', 'Path', 'Resources', 'Provider'],
            array_map(
                static fn (array $panel): array => [
                    $panel['id'],
                    $panel['path'],
                    (string) $panel['resources'],
                    $panel['provider'] ?? '(unknown)',
                ],
                $panels
            )
        );

        $mode = $this->choice(
            'How would you like to install the CRM?',
            ['crm', 'inject'],
            'crm'
        );

        if ($mode === 'inject' && $panelId === null) {
            $panelId = $this->promptForTargetPanel($panels);
        }

        return [$mode, $panelId];
    }

    /**
     * @param  array<int, array{id: string, path: string, resources: int, provider: ?string}>|null  $panels
     */
    private function promptForTargetPanel(?array $panels = null): ?string
    {
        $panels ??= $this->detectPanels();
        $targets = array_values(array_filter($panels, static fn (array $p): bool => $p['id'] !== 'crm'));

        if ($targets === []) {
            $this->error('No non-CRM Filament panels detected to inject into.');

            return null;
        }

        $options = array_column($targets, 'id');

        return $this->choice(
            'Which panel should the CRM be injected into?',
            $options,
            $options[0]
        );
    }

    /**
     * @return array<int, array{id: string, path: string, resources: int, provider: ?string}>
     */
    private function detectPanels(): array
    {
        $registry = app(PanelRegistry::class);

        $providerByPanelId = [];
        foreach (app()->getProviders(PanelProvider::class) as $provider) {
            try {
                $panel = $provider->panel(Panel::make());
                $providerByPanelId[$panel->getId()] = get_class($provider);
            } catch (Throwable) {
                // Skip providers that can't be probed outside their normal registration path.
            }
        }

        $descriptors = [];
        foreach ($registry->all() as $panel) {
            $descriptors[] = [
                'id' => $panel->getId(),
                'path' => $panel->getPath(),
                'resources' => count($panel->getResources()),
                'provider' => $providerByPanelId[$panel->getId()] ?? null,
            ];
        }

        return $descriptors;
    }

    private function installCrmMode(Filesystem $files): int
    {
        $stub = __DIR__ . '/../../stubs/CrmPanelProvider.php.stub';
        $target = app_path('Providers/Filament/CrmPanelProvider.php');

        if (! $files->exists($stub)) {
            $this->error("Missing stub: {$stub}");

            return self::FAILURE;
        }

        if ($files->exists($target) && ! $this->option('force')) {
            $this->warn("CrmPanelProvider already exists at {$target}. Re-run with --force to overwrite.");

            return self::SUCCESS;
        }

        $files->ensureDirectoryExists(dirname($target));
        $files->put($target, $files->get($stub));
        $this->info("Published CrmPanelProvider to {$target}");

        $this->registerProvider($files);

        $this->publishAndRunMigrations();

        $this->patchComposerScripts($files);

        $this->maybeDisableLegacyCrmUi($files);

        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Add the FilamentUser interface + canAccessPanel() to App\Models\User');
        $this->line('     (or rely on the HasCrmAccess trait once it implements FilamentUser).');
        $this->line('  2. Visit /crm to view your panel.');

        return self::SUCCESS;
    }

    /**
     * Publish + run the plugin's own migrations (currently the
     * `crm_invoice_payments` table the invoice Mark Paid action writes to).
     * Spatie derives publish tags from `Package::shortName()`, which strips
     * the `laravel-` prefix — hence `crm-filament-migrations` rather than
     * `laravel-crm-filament-migrations`.
     *
     * Stamps `crm_filament_db_version` afterwards, for the same reason
     * `laravelcrm:filament-update` does: PanelSystemCheck counts a missing
     * marker as behind, so an install that ran the migrations and left the
     * marker unwritten would greet the operator with a banner telling them to
     * run an update over the migrations this command has just applied.
     */
    protected function publishAndRunMigrations(): void
    {
        $publishArguments = ['--tag' => self::MIGRATIONS_PUBLISH_TAG];

        if ($this->option('force')) {
            $publishArguments['--force'] = true;
        }

        $this->call('vendor:publish', $publishArguments);
        $this->call('migrate', ['--force' => true]);

        $this->info('Published and ran the CRM Filament migrations.');

        $this->stampMigratedVersion();
    }

    /**
     * Record the marker, without letting a failure to do so fail the install.
     *
     * Unlike `laravelcrm:filament-update`, where every step is fatal, this runs
     * inside an installer that has already published a panel provider and
     * migrated. Aborting here would leave a half-installed host over a
     * cosmetic-by-comparison problem, so the failure is reported with the
     * one-line remedy instead.
     *
     * The write itself needs core's `crm_settings` table, which
     * `laravelcrm:install` creates — reachable on any host that got this far,
     * except one run with `--skip-crm-install` against a CRM that was never
     * actually installed.
     */
    private function stampMigratedVersion(): void
    {
        try {
            $this->stampPanelDbVersion();
        } catch (Throwable $e) {
            $this->warn('Could not record the panel database version: ' . $e->getMessage());
            $this->warn('Run "php artisan laravelcrm:filament-update" to record it, or the panel will report a database update it does not need.');
        }
    }

    /**
     * Add `@php artisan laravelcrm:filament-upgrade --ansi` to the host's
     * `post-autoload-dump` composer scripts.
     *
     * post-autoload-dump rather than post-update-cmd because it fires on
     * `composer install` as well as `composer update` — so a production
     * `composer install --no-dev` clears the stale Filament component cache
     * without anyone having to know this package ships a command. It is the
     * hook Filament itself uses, for the same reason.
     *
     * Only ever adds the safe, database-free half. `laravelcrm:filament-update`
     * stays explicit and belongs in the deploy script.
     *
     * Ported from laravel-crm's own installer rather than written afresh: the
     * traps here — object-vs-array decoding, the bare-string hook form, never
     * rewriting a file we could not fully parse — are all ones it already
     * handles.
     */
    private function patchComposerScripts(Filesystem $files): void
    {
        if ($this->option('no-composer-hook')) {
            $this->line('Skipping the composer.json post-autoload-dump hook (--no-composer-hook).');

            return;
        }

        $this->info('Configuring composer scripts...');

        $path = base_path('composer.json');

        if (! $files->exists($path)) {
            $this->warn("Could not locate {$path}.");
            $this->printComposerScriptInstructions();

            return;
        }

        $original = $files->get($path);

        // Decoded to objects, not associative arrays. json_decode($json, true)
        // turns an empty JSON object into an empty PHP array, which re-encodes
        // as `[]` — so a `"require-dev": {}` or `"config": {}` anywhere in the
        // file would come back out as a JSON array and composer would reject
        // the manifest. Objects round-trip as objects.
        $composer = json_decode($original);

        // Never rewrite a file we could not fully understand — a composer.json
        // is hand-maintained and losing it is worse than not patching it.
        if (! $composer instanceof stdClass || json_last_error() !== JSON_ERROR_NONE) {
            $this->warn("Could not parse {$path}: " . json_last_error_msg());
            $this->printComposerScriptInstructions();

            return;
        }

        $scripts = $composer->scripts ?? null;

        // An absent "scripts", or one written as an empty JSON array, is the
        // object we are about to build. Anything else that is not an object is
        // something we do not understand.
        if ($scripts === null || $scripts === []) {
            $scripts = new stdClass;
        }

        if (! $scripts instanceof stdClass) {
            $this->warn("Unexpected \"scripts\" value in {$path}.");
            $this->printComposerScriptInstructions();

            return;
        }

        $hook = $scripts->{'post-autoload-dump'} ?? [];

        // Composer allows a bare string as well as an array of them.
        $hook = is_array($hook) ? array_values($hook) : [$hook];

        foreach ($hook as $line) {
            if (is_string($line) && str_contains($line, 'laravelcrm:filament-upgrade')) {
                $this->line('composer.json already runs laravelcrm:filament-upgrade. Skipping.');

                return;
            }
        }

        // Appended, not prepended: package:discover has to have run before an
        // artisan command from a package can be resolved at all.
        $hook[] = self::COMPOSER_HOOK_ENTRY;

        $scripts->{'post-autoload-dump'} = $hook;
        $composer->scripts = $scripts;

        $encoded = json_encode(
            $composer,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($encoded === false) {
            $this->warn("Could not re-encode {$path}.");
            $this->printComposerScriptInstructions();

            return;
        }

        $files->put($path, $encoded . PHP_EOL);

        $this->info('Added "' . self::COMPOSER_HOOK_ENTRY . '" to post-autoload-dump in composer.json');
    }

    /**
     * Print the line to add by hand when composer.json could not be patched.
     */
    private function printComposerScriptInstructions(): void
    {
        $this->warn('   Add this to the "post-autoload-dump" scripts in your composer.json:');
        $this->warn('     "' . self::COMPOSER_HOOK_ENTRY . '"');
        $this->warn('   Without it, `composer update` will not clear the cached Filament panel components.');
    }

    private function maybeDisableLegacyCrmUi(Filesystem $files): void
    {
        if (! config('laravel-crm.user_interface', true)) {
            return;
        }

        $envLine = 'LARAVEL_CRM_USER_INTERFACE=false';
        $confirmed = $this->confirm(
            'Add LARAVEL_CRM_USER_INTERFACE=false to your .env now (disables the legacy /crm Livewire UI so the Filament CRM panel can take over /crm)?'
        );

        if (! $confirmed) {
            $this->line("Add this line to your .env manually: {$envLine}");

            return;
        }

        $envPath = base_path('.env');

        if (! $files->exists($envPath)) {
            $this->warn('.env file not found; add this line manually: ' . $envLine);

            return;
        }

        $contents = $files->get($envPath);

        if (preg_match('/^\s*LARAVEL_CRM_USER_INTERFACE\s*=/m', $contents) === 1) {
            $this->line('LARAVEL_CRM_USER_INTERFACE is already set in .env; leaving as-is.');

            return;
        }

        $suffix = (str_ends_with($contents, "\n") || $contents === '') ? '' : "\n";
        $files->put($envPath, $contents . $suffix . $envLine . "\n");
        $this->info('Appended LARAVEL_CRM_USER_INTERFACE=false to .env.');
    }

    private function installInjectMode(Filesystem $files, ?string $panelId): int
    {
        if ($panelId === null || $panelId === '') {
            $this->error('Target panel id is required for inject mode. Pass --panel=<id>.');

            return self::FAILURE;
        }

        $panel = app(PanelRegistry::class)->get($panelId);

        if ($panel === null) {
            $this->error("Panel '{$panelId}' was not found in the registry.");

            return self::FAILURE;
        }

        $providerClass = $this->resolveProviderClassForPanel($panelId);

        if ($providerClass === null) {
            $this->error("Could not locate a PanelProvider class for panel '{$panelId}'.");

            return self::FAILURE;
        }

        $providerFile = (new ReflectionClass($providerClass))->getFileName();

        if ($providerFile === false || ! $files->exists($providerFile)) {
            $this->error("Could not locate the source file for {$providerClass}.");

            return self::FAILURE;
        }

        $conflicts = $this->detectConflicts($panel);

        if ($conflicts !== [] && ! $this->option('force')) {
            $this->error("Resource slug conflicts detected on panel '{$panelId}'. File was not modified.");
            $this->renderConflictTable($conflicts);

            return self::FAILURE;
        }

        // Inject mode needs the plugin's own tables just as much as crm mode
        // does — `crm_invoice_payments` backs the invoice Mark Paid history,
        // which is otherwise silently skipped by its Schema::hasTable() guard.
        // Run this before the provider edit so it also covers the
        // could-not-auto-inject path, which still returns SUCCESS.
        $this->publishAndRunMigrations();

        $this->patchComposerScripts($files);

        $contents = $files->get($providerFile);
        $original = $contents;

        if (! str_contains($contents, 'LaravelCrmPlugin::make()')) {
            $injected = $this->injectPluginCall($contents);

            if ($injected === null) {
                $this->warn("Could not auto-inject LaravelCrmPlugin into {$providerFile}; add this call to the returned \$panel chain manually:");
                $this->line('    ->plugin(\\VentureDrake\\LaravelCrmFilament\\LaravelCrmPlugin::make())');

                return self::SUCCESS;
            }

            $contents = $injected;
        } else {
            $this->line("LaravelCrmPlugin::make() is already present in {$providerFile}; skipping plugin insertion.");
        }

        $contents = $this->addLaravelCrmPluginImport($contents);

        if ($contents !== $original) {
            $files->put($providerFile, $contents);
            $this->info("Injected LaravelCrmPlugin into {$providerFile}.");
        }

        $panelPath = $panel->getPath();
        $this->newLine();
        $this->info("Plugin registered on the `{$panelId}` panel. Visit /{$panelPath} to view.");

        return self::SUCCESS;
    }

    private function resolveProviderClassForPanel(string $panelId): ?string
    {
        foreach (app()->getProviders(PanelProvider::class) as $provider) {
            try {
                $panel = $provider->panel(Panel::make());
            } catch (Throwable) {
                continue;
            }

            if ($panel->getId() === $panelId) {
                return get_class($provider);
            }
        }

        return null;
    }

    /**
     * Regex-insert `->plugin(LaravelCrmPlugin::make())` before the terminating `;`
     * of a `return $panel->...;` chain. Returns null if no safe anchor is found.
     */
    private function injectPluginCall(string $contents): ?string
    {
        // Match `return $panel` followed by chain content that does not contain
        // a `;` immediately followed by whitespace then `}` — i.e. stop at the
        // first `;` that closes off the panel() method body.
        $pattern = '/(\breturn\s+\$panel\b(?:(?!;\s*\}).)*);(\s*\})/s';

        $updated = preg_replace_callback(
            $pattern,
            static function (array $m): string {
                $chain = $m[1];

                // Detect the indent used by the last chain call so the injected
                // line matches the surrounding style.
                $indent = '            ';
                if (preg_match_all('/\n([ \t]+)->/', $chain, $indentMatches) > 0) {
                    $last = end($indentMatches[1]);
                    if (is_string($last)) {
                        $indent = $last;
                    }
                }

                return $chain . "\n" . $indent . '->plugin(LaravelCrmPlugin::make());' . $m[2];
            },
            $contents,
            1,
            $count
        );

        if ($updated === null || $count === 0) {
            return null;
        }

        return $updated;
    }

    private function addLaravelCrmPluginImport(string $contents): string
    {
        $useStatement = 'use VentureDrake\\LaravelCrmFilament\\LaravelCrmPlugin;';

        if (str_contains($contents, $useStatement)) {
            return $contents;
        }

        // Insert after the last existing `use` statement in the file header.
        $updated = preg_replace_callback(
            '/^((?:use [^;]+;\r?\n)+)/m',
            static fn (array $m): string => $m[1] . $useStatement . "\n",
            $contents,
            1,
            $count
        );

        if ($updated !== null && $count === 1) {
            return $updated;
        }

        // Fall back to inserting after the namespace declaration.
        $updated = preg_replace(
            '/^(namespace [^;]+;\r?\n)/m',
            "$1\n{$useStatement}\n",
            $contents,
            1,
            $count
        );

        if ($updated !== null && $count === 1) {
            return $updated;
        }

        return $contents;
    }

    /**
     * Compare the plugin's would-be resource list against a target panel's resources
     * and return one descriptor per colliding `getSlug()`. Panel id/path collisions are
     * intentionally NOT surfaced here — this only covers resource-slug conflicts.
     *
     * @return array<int, array{slug: string, existing: class-string, plugin: class-string}>
     */
    private function detectConflicts(Panel $target): array
    {
        $pluginResources = LaravelCrmPlugin::make()->getResources();

        $existingBySlug = [];
        foreach ($target->getResources() as $existing) {
            $existingBySlug[$existing::getSlug()] = $existing;
        }

        $conflicts = [];
        foreach ($pluginResources as $pluginResource) {
            $slug = $pluginResource::getSlug();

            if (! isset($existingBySlug[$slug])) {
                continue;
            }

            $existing = $existingBySlug[$slug];

            // A resource class registered on both sides isn't really a collision;
            // only flag when two distinct classes claim the same slug.
            if ($existing === $pluginResource) {
                continue;
            }

            $conflicts[] = [
                'slug' => $slug,
                'existing' => $existing,
                'plugin' => $pluginResource,
            ];
        }

        return $conflicts;
    }

    /**
     * @param  array<int, array{slug: string, existing: class-string, plugin: class-string}>  $conflicts
     */
    private function renderConflictTable(array $conflicts): void
    {
        $this->table(
            ['Slug', 'Existing resource', 'Plugin resource'],
            array_map(
                static fn (array $conflict): array => [
                    $conflict['slug'],
                    $conflict['existing'],
                    $conflict['plugin'],
                ],
                $conflicts
            )
        );

        $this->line('Re-run with `--mode=crm` to publish a standalone /crm panel instead of injecting.');
    }

    protected function registerProvider(Filesystem $files): void
    {
        $providersFile = base_path('bootstrap/providers.php');

        if (! $files->exists($providersFile)) {
            $this->warn('bootstrap/providers.php not found; register App\\Providers\\Filament\\CrmPanelProvider manually.');

            return;
        }

        $contents = $files->get($providersFile);
        $providerClass = 'App\\Providers\\Filament\\CrmPanelProvider::class';

        if (str_contains($contents, 'CrmPanelProvider::class')) {
            $this->line('CrmPanelProvider already registered in bootstrap/providers.php.');

            return;
        }

        $updated = preg_replace(
            '/return\s*\[\s*\n/',
            "return [\n    {$providerClass},\n",
            $contents,
            1,
            $count
        );

        if ($count === 0 || $updated === null) {
            $this->warn('Could not auto-register CrmPanelProvider in bootstrap/providers.php; add it manually.');

            return;
        }

        $files->put($providersFile, $updated);
        $this->info('Registered CrmPanelProvider in bootstrap/providers.php.');
    }
}
