<?php

namespace VentureDrake\LaravelCrmFilament\Console;

use Filament\Panel;
use Filament\PanelProvider;
use Filament\PanelRegistry;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use ReflectionClass;
use Throwable;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Support\TenancyGuard;

class InstallCommand extends Command
{
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
        {--allow-teams : Install anyway when laravel-crm.teams is on. The panel is not tenant-aware; see the README.}';

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
     * first and warn rather than fail with an "option does not exist" error.
     */
    private function callLaravelCrmInstall(): int
    {
        $arguments = [];

        $modules = $this->option('modules');

        if (is_string($modules) && $modules !== '') {
            if ($this->laravelCrmInstallSupportsModules()) {
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

    private function laravelCrmInstallSupportsModules(): bool
    {
        $application = $this->getApplication();

        if ($application === null) {
            return false;
        }

        try {
            $command = $application->find('laravelcrm:install');
        } catch (Throwable) {
            return false;
        }

        return $command->getDefinition()->hasOption('modules');
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
