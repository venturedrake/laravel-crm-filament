<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Resolves the `logo_file` setting into a browsable URL.
 *
 * `logo_file` holds a path relative to the `public` disk root — that is what
 * GeneralSettings' uploader writes and what base's SettingEdit
 * (vendor/venturedrake/laravel-crm/src/Livewire/Settings/SettingEdit.php:322-333)
 * writes, and base's PDF views read it back as `asset('storage/'.$logo)`.
 * Fully-qualified URLs are passed through untouched so hosts that pointed the
 * setting at a CDN keep working.
 */
class LogoUrl
{
    public static function resolve(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::disk('public')->url(ltrim($path, '/'));
    }
}
