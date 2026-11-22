<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use Composer\InstalledVersions;
use Filament\Support\Assets\Asset;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrm\Scopes\BelongsToTeamsScope;

/**
 * The plugin's counterpart to core's SystemCheckService: "has
 * `laravelcrm:filament-update` been run against the installed panel code?"
 *
 * Core's service answers the same question for core's own schema and knows
 * nothing about this package — it compares against `config('laravel-crm
 * .version')` and only counts core's migration files. This plugin owns its own
 * table (`crm_invoice_payments`) and its own semver line, so it needs its own
 * marker and its own answer.
 *
 * Scoped down to the two signals that apply here. Core's third — a
 * hand-maintained list of `db_update_*` backfill flags — has no equivalent
 * because this package performs no one-shot data backfills; if that ever
 * changes, the two signals below still cover it, since a backfill ships with a
 * version bump.
 *
 * It also answers one question core cannot: "are Filament's published CSS
 * and JS the ones the installed Filament ships?" Filament renders its
 * components on the server, but the browser runs whatever `filament:assets`
 * last copied into `public/`. When composer moves Filament forward and the
 * hook that republishes those copies never fires, the new markup waits on
 * Alpine components and CSS that the old copies do not have, and selects,
 * tables and actions stay inert with nothing on the server side looking wrong.
 */
class PanelSystemCheck
{
    public const CACHE_KEY = 'app.crm-filament-system-check';

    public const CACHE_TTL = 300; // 5 minutes

    /**
     * The one alert type this check produces. Named apart from core's
     * `db_update_required` on purpose: both can be outstanding at once, they
     * are fixed by different commands, and a shared type would let one mask
     * the other in the banner's alert list.
     */
    public const PANEL_DB_UPDATE_REQUIRED = 'panel_db_update_required';

    /**
     * The published copies of Filament's own assets differ from the files the
     * installed Filament ships. Fixed by `laravelcrm:filament-upgrade`, which
     * runs `filament:assets`.
     */
    public const FILAMENT_ASSETS_STALE = 'filament_assets_stale';

    /**
     * The setting `laravelcrm:filament-update` stamps with
     * config('laravel-crm-filament.version') when it completes.
     *
     * Distinct from core's `db_version` for the same reason the two commands
     * are distinct: `laravelcrm:update` moves core's marker and does not touch
     * this package's migrations at all.
     */
    public const DB_VERSION_SETTING = 'crm_filament_db_version';

    /**
     * Run the checks and return the alerts they produce. Uncached — callers
     * that render on every request should use alerts() instead.
     *
     * @return array<int, array<string, mixed>>
     */
    public function check(): array
    {
        $alerts = [];

        // A missing settings table means the install is structurally behind,
        // which is core's UPGRADE_REQUIRED case, not this one. The database
        // half reads that table, so it says nothing rather than guess.
        if ($this->settingsTableExists()) {
            $versionBehind = $this->dbVersionBehind();
            $migrationsPending = $this->hasPendingMigrations();

            if ($versionBehind || $migrationsPending) {
                $alerts[] = [
                    'type' => self::PANEL_DB_UPDATE_REQUIRED,
                    'level' => 'info',
                    'version_behind' => $versionBehind,
                    'migrations_pending' => $migrationsPending,
                ];
            }
        }

        // Not a database question, so not behind the settings-table guard: a
        // host with stale assets has broken selects whatever its schema says.
        $stale = $this->staleFilamentAssets();

        if ($stale !== []) {
            $alerts[] = [
                'type' => self::FILAMENT_ASSETS_STALE,
                'level' => 'warning',
                // In the payload so the combined signature moves with the next
                // Filament upgrade, and a banner dismissed at this version
                // comes back if the same thing happens at the next one.
                'filament_version' => $this->installedFilamentVersion(),
                'stale_count' => count($stale),
            ];
        }

        return $alerts;
    }

    /**
     * The public paths, relative to `public/`, of Filament's published assets
     * that differ from the files the installed Filament ships.
     *
     * Walks Filament's own asset registry — the list `filament:assets` copies
     * from — rather than a hard-coded set of paths, so it follows whatever the
     * installed Filament registers. Only `filament/*` packages are compared:
     * those are the files Filament's server-rendered markup depends on, and
     * another plugin's assets are that plugin's business.
     *
     * A published file that does not exist is skipped, not reported. A host
     * serving assets from a CDN or `ASSET_URL` has none locally, and neither
     * does a test bench; flagging those would be a banner nobody can clear.
     *
     * Wrapped because this runs on a page render: an unreadable `public/` must
     * degrade to "nothing to report", not throw on the way to a banner.
     *
     * @return array<int, string>
     */
    public function staleFilamentAssets(): array
    {
        try {
            if (! class_exists(FilamentAsset::class)) {
                return [];
            }

            /** @var array<int, Asset> $assets */
            $assets = [
                ...FilamentAsset::getStyles(),
                ...FilamentAsset::getScripts(),
                ...FilamentAsset::getAlpineComponents(),
            ];

            $stale = [];

            foreach ($assets as $asset) {
                if (! str_starts_with((string) $asset->getPackage(), 'filament/')) {
                    continue;
                }

                if ($asset->isRemote()) {
                    continue;
                }

                $source = $asset->getPath();

                // A directory source is a lazy-loaded chunk folder, not a file
                // to compare, and a missing one is Filament's problem to report.
                if ($source === null || ! is_file($source)) {
                    continue;
                }

                $published = $asset->getPublicPath();

                if (! is_file($published)) {
                    continue;
                }

                if ($this->filesDiffer($source, $published)) {
                    $stale[] = ltrim(substr($published, strlen(public_path())), '/\\');
                }
            }

            return $stale;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Size first, because it is free and settles nearly every real case — a
     * Filament release rebuilds its bundles. The hash only runs when the sizes
     * agree.
     */
    protected function filesDiffer(string $a, string $b): bool
    {
        if (filesize($a) !== filesize($b)) {
            return true;
        }

        return md5_file($a) !== md5_file($b);
    }

    protected function installedFilamentVersion(): ?string
    {
        foreach (['filament/filament', 'filament/support'] as $package) {
            try {
                if (InstalledVersions::isInstalled($package)) {
                    return InstalledVersions::getPrettyVersion($package);
                }
            } catch (Throwable) {
                // Fall through to the next package.
            }
        }

        return null;
    }

    /**
     * The cached alerts. Empty when update notifications are switched off.
     *
     * Reads core's `laravel-crm.update_notifications` rather than introducing a
     * second switch: an operator who turned the CRM's notifications off does
     * not want the panel's either, and two flags for one decision is one flag
     * too many.
     *
     * @return array<int, array<string, mixed>>
     */
    public function alerts(): array
    {
        if (! config('laravel-crm.update_notifications')) {
            return [];
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return $this->check();
        });
    }

    /**
     * A stable fingerprint of the current alerts, so a UI can tell whether
     * anything changed since the user last dismissed them. Null when there is
     * nothing to report.
     */
    public function signature(): ?string
    {
        $alerts = $this->alerts();

        if (count($alerts) === 0) {
            return null;
        }

        return substr(sha1((string) json_encode($alerts)), 0, 32);
    }

    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * True when `laravelcrm:filament-update` has not been run against the
     * installed panel code.
     *
     * Read install-wide, with the team scope dropped: the marker is written
     * from a console command, which has no authenticated user and therefore no
     * team, so a team-scoped read cannot see the row it just wrote.
     *
     * A missing marker counts as behind. Either the host predates the marker —
     * in which case it has never run this command and should — or the database
     * was never stamped; both want the same action.
     */
    public function dbVersionBehind(): bool
    {
        $codeVersion = (string) config('laravel-crm-filament.version');

        // No version to compare against — say nothing rather than nag. This is
        // the shape a host in the middle of a `config:cache` from an older
        // release sees.
        if ($codeVersion === '') {
            return false;
        }

        try {
            $rows = Setting::query()
                ->withoutGlobalScope(BelongsToTeamsScope::class)
                ->where('name', self::DB_VERSION_SETTING)
                ->get(['value']);
        } catch (Throwable) {
            return false;
        }

        if ($rows->isEmpty()) {
            return true;
        }

        // Any row behind means behind, so per-team duplicates cannot mask an
        // outstanding update.
        foreach ($rows as $row) {
            // getAttribute() rather than ->value: core's Setting declares no
            // properties, so the magic accessor is untyped.
            $dbVersion = (string) $row->getAttribute('value');

            if ($dbVersion === '' || version_compare($dbVersion, $codeVersion, '<')) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when *this package* has migration files that have not been run.
     *
     * Scoped to this package's own migrations, and it must stay that way. The
     * host's migrations and every other package's live in the same
     * `database/migrations` directory and in the migrator's own registered path
     * list, and an unrun one of those says nothing about whether the CRM
     * panel's database is behind — it would just raise a CRM banner telling the
     * operator to run a CRM command over somebody else's pending migration.
     *
     * Guarded on repositoryExists(): a host with no migrations table has never
     * migrated at all, which is core's UPGRADE_REQUIRED case. Wrapped because
     * this runs on a page render — an unintrospectable connection must degrade
     * to "nothing to report", not throw on the way to rendering a banner.
     */
    public function hasPendingMigrations(): bool
    {
        try {
            $migrator = app('migrator');

            if (! $migrator->repositoryExists()) {
                return false;
            }

            $files = $this->panelMigrationFiles($migrator);

            if ($files === []) {
                return false;
            }

            $ran = $migrator->getRepository()->getRan();

            return count(array_diff(array_keys($files), $ran)) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The migration files this package is responsible for, keyed by migration
     * name.
     *
     * Two sources, because the package can deliver migrations two ways:
     *
     *  - the `.php.stub` set, published into the host's `database/migrations`
     *    under the timestamped name Spatie mints for it;
     *  - real `.php` files in the package's own `database/migrations`, which
     *    `runsMigrations()` hands to `loadMigrationsFrom()`. There are none
     *    today (Laravel's Migrator only globs `*_*.php`, so the stub is never
     *    loaded that way), but the path is included so one added later
     *    announces itself without anyone having to remember this class.
     *
     * @param  Migrator  $migrator
     * @return array<string, string>
     */
    protected function panelMigrationFiles($migrator): array
    {
        $files = $migrator->getMigrationFiles([$this->packageMigrationPath()]);

        $stubs = $this->publishedStubNames();

        if ($stubs === []) {
            return $files;
        }

        foreach ($migrator->getMigrationFiles([database_path('migrations')]) as $name => $path) {
            // A published stub keeps its source filename behind the timestamp:
            // 2026_08_10_120000_create_laravel_crm_invoice_payments_table.
            $source = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $name);

            if ($source !== null && isset($stubs[$source])) {
                $files[$name] = $path;
            }
        }

        return $files;
    }

    /**
     * Where this package's migrations — stubs and any future real files — live.
     */
    protected function packageMigrationPath(): string
    {
        return __DIR__ . '/../../database/migrations';
    }

    /**
     * The base names of every `.php.stub` this package publishes, as a lookup.
     *
     * Read off disk rather than hard-coded so it cannot drift from what
     * `configurePackage()` actually registers.
     *
     * @return array<string, true>
     */
    protected function publishedStubNames(): array
    {
        $names = [];

        foreach (glob($this->packageMigrationPath() . '/*.php.stub') ?: [] as $stub) {
            $names[basename($stub, '.php.stub')] = true;
        }

        return $names;
    }

    /**
     * Whether core's settings table — the one the version marker lives in —
     * exists yet.
     */
    protected function settingsTableExists(): bool
    {
        try {
            return Schema::hasTable(config('laravel-crm.db_table_prefix') . 'settings');
        } catch (Throwable) {
            return false;
        }
    }
}
