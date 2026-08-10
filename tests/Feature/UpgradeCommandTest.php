<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use VentureDrake\LaravelCrmFilament\Console\UpgradeCommand;

/**
 * `laravelcrm:filament-upgrade`: the half of the upgrade the host's composer
 * hook fires.
 *
 * It runs on every `composer install`, including on a production box mid-deploy
 * with no TTY and a database that may be unreachable, mid-migration, or
 * belonging to a different release. So: no database, no prompts, and SUCCESS
 * when there is nothing it can do.
 */

/**
 * A stand-in for a cache-clearing command, registered over the real one so a
 * test can see that it was called rather than only that nothing threw.
 */
class FakeCacheClearCommand extends Command
{
    /** @var array<string, int> */
    public static array $calls = [];

    protected $description = 'Fake cache-clearing command.';

    public function __construct(string $name)
    {
        $this->signature = $name;

        parent::__construct();
    }

    public function handle(): int
    {
        $name = (string) $this->getName();

        self::$calls[$name] = (self::$calls[$name] ?? 0) + 1;

        return self::SUCCESS;
    }
}

class ThrowingCacheClearCommand extends Command
{
    protected $signature = 'config:clear';

    protected $description = 'A cache clear that blows up.';

    public function handle(): int
    {
        throw new RuntimeException('cache directory is not writable');
    }
}

/**
 * The command source, for the guarantees that are properties of the code rather
 * than of any observable state.
 */
function upgradeCommandSource(): string
{
    return (string) file_get_contents((new ReflectionClass(UpgradeCommand::class))->getFileName());
}

beforeEach(function () {
    FakeCacheClearCommand::$calls = [];
});

it('clears the Filament component cache and the framework caches, and exits SUCCESS', function () {
    foreach ([UpgradeCommand::FILAMENT_CACHE_COMMAND, ...UpgradeCommand::CACHE_COMMANDS] as $name) {
        Artisan::registerCommand(new FakeCacheClearCommand($name));
    }

    $this->artisan('laravelcrm:filament-upgrade')
        ->expectsOutputToContain('caches are up to date')
        ->assertSuccessful();

    // filament:optimize-clear is the load-bearing one: Filament caches the
    // panel's discovered components, and a stale cache after an upgrade means
    // new resources and pages simply do not appear.
    expect(FakeCacheClearCommand::$calls)->toHaveKey(UpgradeCommand::FILAMENT_CACHE_COMMAND);

    foreach (UpgradeCommand::CACHE_COMMANDS as $name) {
        expect(FakeCacheClearCommand::$calls)->toHaveKey($name);
    }
});

it('points the operator at the command that does the database half', function () {
    $this->artisan('laravelcrm:filament-upgrade')
        ->expectsOutputToContain('laravelcrm:filament-update')
        ->assertSuccessful();
});

it('survives a cache command that throws, rather than failing the composer run', function () {
    Artisan::registerCommand(new ThrowingCacheClearCommand);

    // A build that cannot clear a cache is not a broken install, and this
    // command's exit code is what fails the host's `composer install`.
    $this->artisan('laravelcrm:filament-upgrade')
        ->expectsOutputToContain('Could not run config:clear')
        ->assertSuccessful();
});

it('probes for filament:optimize-clear rather than assuming it is registered', function () {
    // Absent on a host with only filament/forms installed, and Command::call()
    // on an unknown name throws — which would fail the composer run this
    // command exists to survive.
    expect(Artisan::all())->toHaveKey(UpgradeCommand::FILAMENT_CACHE_COMMAND);

    expect(upgradeCommandSource())->toContain('$application->has(self::FILAMENT_CACHE_COMMAND)');
});

it('touches no database at all', function () {
    // Asserted on the source, in the spirit of UpdatesPageTest's "never
    // triggers laravelcrm:update": there is no state this command could be
    // observed *not* to have changed, so the guarantee is a property of the
    // code. During a composer run the database may be unreachable,
    // mid-migration, or belong to an entirely different release.
    foreach (["'migrate'", "'db:seed'", 'Setting::', 'DB::', 'laravel-crm.settings'] as $forbidden) {
        expect(upgradeCommandSource())->not->toContain($forbidden);
    }
});

it('never prompts — there is nobody at the other end of a composer hook', function () {
    expect(upgradeCommandSource())->not->toContain('$this->confirm(')
        ->not->toContain('$this->ask(')
        ->not->toContain('$this->choice(');
});

it('does not call core laravelcrm:upgrade, which has its own composer hook', function () {
    // Core's installer adds its own post-autoload-dump entry, so on a host
    // running both packages both hooks fire. Calling core's from here would
    // double the work on every composer install.
    expect(upgradeCommandSource())->not->toContain("call('laravelcrm:upgrade'")
        ->not->toContain("callSilent('laravelcrm:upgrade'");
});
