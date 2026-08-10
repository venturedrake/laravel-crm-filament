<?php

namespace VentureDrake\LaravelCrmFilament\Console\Concerns;

use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

/**
 * Ask the artisan application what the *installed* base package can actually
 * be told to do, before telling it.
 *
 * Every command this plugin forwards to lives in `venturedrake/laravel-crm`,
 * whose composer constraint spans several minor versions, and options arrive in
 * those minors: `--modules` on `laravelcrm:install` in 2.3.0, `--force` on
 * `laravelcrm:update` in 2.4.0. Passing an option the installed version does
 * not define is not a graceful degradation — Symfony throws
 * `RuntimeException: The "--force" option does not exist`, and the whole
 * install or update aborts.
 *
 * So the rule for every cross-package call is: probe the definition, forward
 * what is supported, say plainly what was dropped.
 */
trait ProbesConsoleCommands
{
    /**
     * The registered command, or null when this application has no such
     * command.
     *
     * Swallows Symfony's CommandNotFoundException (and the ambiguous-name
     * variant) rather than letting it escape: "the base package is older than I
     * expected" and "the base package is not installed" are both answers this
     * plugin handles, not crashes.
     */
    protected function findConsoleCommand(string $command): ?SymfonyCommand
    {
        $application = $this->getApplication();

        if ($application === null) {
            return null;
        }

        try {
            return $application->find($command);
        } catch (Throwable) {
            return null;
        }
    }

    protected function consoleCommandExists(string $command): bool
    {
        return $this->findConsoleCommand($command) !== null;
    }

    /**
     * Whether the installed version of `$command` defines `--$option`.
     */
    protected function commandSupportsOption(string $command, string $option): bool
    {
        $resolved = $this->findConsoleCommand($command);

        if ($resolved === null) {
            return false;
        }

        return $resolved->getDefinition()->hasOption($option);
    }
}
