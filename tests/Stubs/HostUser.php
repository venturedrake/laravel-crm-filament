<?php

namespace VentureDrake\LaravelCrmFilament\Tests\Stubs;

/**
 * A host user model that is neither `App\Models\User` nor the package's own
 * `Stubs\User`, and lives in its own table — so a query against the hardcoded
 * `App\Models\User` of core CRM's `SendImportPasswordReset` can never find a
 * record here. Used to prove the plugin's invite job resolves
 * `config('auth.providers.users.model')`.
 */
class HostUser extends User
{
    protected $table = 'host_users';
}
