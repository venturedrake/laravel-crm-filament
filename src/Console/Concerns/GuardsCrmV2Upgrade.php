<?php

namespace VentureDrake\LaravelCrmFilament\Console\Concerns;

use Illuminate\Console\Events\CommandStarting;
use RuntimeException;
use VentureDrake\LaravelCrm\Listeners\GuardCrmV2Upgrade;

/**
 * Refuse to migrate a CRM database that is still on v1.
 *
 * Core 2.6.0 refuses `migrate` and `laravelcrm:update` there, because running
 * either before `laravelcrm:v2` creates empty v2 tables next to the v1 ones
 * and strands the v1 data. It checks on CommandStarting, which only fires for
 * the command that was typed, never for a nested `$this->call()`. The panel's
 * install and update commands run both nested, so they would slip past it.
 * They run core's own check instead, before anything is touched.
 */
trait GuardsCrmV2Upgrade
{
    /**
     * Core's refusal message, or null when the database is not on v1.
     */
    protected function crmV2UpgradeBlocker(string $command): ?string
    {
        try {
            app(GuardCrmV2Upgrade::class)->handle(new CommandStarting($command, $this->input, $this->output));
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }
}
