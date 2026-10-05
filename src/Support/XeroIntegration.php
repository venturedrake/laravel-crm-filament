<?php

namespace VentureDrake\LaravelCrmFilament\Support;

/**
 * The panel's gate in front of `dcblogdev/laravel-xero`.
 *
 * Core 2.4.5 moved that package from `require` to `suggest` — it caps
 * `guzzlehttp/guzzle` at ^7 in every version it has published, so it was
 * holding core off Guzzle 8 — and added its own
 * `VentureDrake\LaravelCrm\Support\XeroIntegration` to gate every entry point.
 * That class does not exist on 2.4.2–2.4.4, which this plugin still supports,
 * so the panel probes the same thing core does, the package's service
 * provider, rather than calling core's helper.
 *
 * Class names are strings so neither autoload nor static analysis needs the
 * package to be present.
 */
class XeroIntegration
{
    private const SERVICE_PROVIDER = 'Dcblogdev\\Xero\\XeroServiceProvider';

    /**
     * Test seam: force installed() either way. The suite always has the
     * package (it is in require-dev), so the not-installed branch is otherwise
     * unreachable from a test.
     */
    public static ?bool $installedUsing = null;

    /**
     * Whether `dcblogdev/laravel-xero` is installed in the host app.
     */
    public static function installed(): bool
    {
        return static::$installedUsing ?? class_exists(self::SERVICE_PROVIDER);
    }

    /**
     * Whether the package is installed *and* holds a live connection. Resolves
     * the `xero` binding the package's facade proxies to, so a failure to
     * reach Xero reads as "not connected" rather than an exception on render.
     */
    public static function connected(): bool
    {
        if (! static::installed()) {
            return false;
        }

        try {
            return (bool) app('xero')->isConnected();
        } catch (\Throwable) {
            return false;
        }
    }
}
