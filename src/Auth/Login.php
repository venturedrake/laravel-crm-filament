<?php

namespace VentureDrake\LaravelCrmFilament\Auth;

use Filament\Auth\Pages\Login as FilamentLogin;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use VentureDrake\LaravelCrmFilament\Support\LogoUrl;

/**
 * Branded login screen for the CRM panel.
 *
 * Pulls the organization name + logo from `SettingService` so the panel
 * looks native to the host's CRM. Falls back to the panel-level brand
 * primary color `#05b3a9` when no setting is configured.
 */
class Login extends FilamentLogin
{
    public function getHeading(): string | Htmlable | null
    {
        $name = $this->brandName();

        if (blank($name)) {
            return parent::getHeading();
        }

        $logo = $this->brandLogoUrl();

        if (blank($logo)) {
            return $name;
        }

        return new HtmlString(
            '<div style="display:flex;flex-direction:column;align-items:center;gap:0.75rem;">'
            . '<img src="' . e($logo) . '" alt="' . e($name) . '" style="max-height:64px;max-width:240px;object-fit:contain;" />'
            . '<span>' . e($name) . '</span>'
            . '</div>'
        );
    }

    public function getSubheading(): string | Htmlable | null
    {
        return parent::getSubheading();
    }

    protected function brandName(): ?string
    {
        return app('laravel-crm.settings')->get('organization_name') ?: config('app.name');
    }

    protected function brandLogoUrl(): ?string
    {
        return LogoUrl::resolve(app('laravel-crm.settings')->get('logo_file'));
    }

    public function brandPrimaryColor(): string
    {
        return app('laravel-crm.settings')->get('primary_color') ?: '#05b3a9';
    }
}
