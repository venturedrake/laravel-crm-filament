<?php

namespace VentureDrake\LaravelCrmFilament\Concerns;

use Filament\Pages\Page;

/**
 * Gates a Filament Page behind a core CRM Spatie permission.
 *
 * Filament's `Concerns\CanAuthorizeAccess::canAccess()` defaults to `true`, so
 * a custom Page is reachable by every authenticated panel user unless it says
 * otherwise. Core CRM gates the equivalent screens behind `view crm settings` /
 * `edit crm settings` / `view crm updates` (see
 * vendor/venturedrake/laravel-crm/resources/views/layouts/partials/nav.blade.php
 * and Http/Middleware/SystemCheck.php), so the plugin pages mirror those
 * permission names.
 *
 * Using classes MUST declare the permission they require:
 *
 *     protected static string $crmPermission = 'view crm settings';
 *
 * The property is deliberately not declared here — PHP treats a trait property
 * and a using-class property with different default values as an incompatible
 * composition (fatal error), so each page owns its own declaration.
 *
 * `canAccess()` is consulted by Filament both when routing to the page
 * (`mountCanAuthorizeAccess()` / `hydrateCanAuthorizeAccess()` abort 403) and
 * when registering navigation; `shouldRegisterNavigation()` here additionally
 * hides the sidebar entry so unauthorized users are not shown a link that would
 * only 403.
 *
 * @phpstan-require-extends Page
 */
trait AuthorizesCrmSettingsPage
{
    use ChecksCrmPermissions;

    /**
     * Permission required to mutate CRM settings. Shared by every settings
     * page — only the read permission varies per page.
     */
    public const CRM_EDIT_SETTINGS_PERMISSION = 'edit crm settings';

    public static function canAccess(): bool
    {
        return static::userHasCrmPermission(static::$crmPermission);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && static::canAccess();
    }

    /**
     * Whether the current user may write CRM settings, as opposed to merely
     * viewing them.
     */
    public static function canEditCrmSettings(): bool
    {
        return static::userHasCrmPermission(static::CRM_EDIT_SETTINGS_PERMISSION);
    }

    /**
     * Guard for the write paths (`save()` and friends). Kept separate from
     * `canAccess()` so a `view crm settings`-only user can read the page but
     * cannot persist anything, matching core CRM.
     */
    protected function authorizeCrmSettingsEdit(): void
    {
        abort_unless(static::canEditCrmSettings(), 403);
    }
}
