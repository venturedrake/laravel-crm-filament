<?php

namespace VentureDrake\LaravelCrmFilament\Concerns;

use Closure;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Support\Modules;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Resources\Xero\XeroContactResource;

/**
 * Exposes a `xeroSyncStateSection()` factory that returns a Filament
 * infolist Section surfacing the linked Xero mirror's status, last
 * sync timestamp, and any sync error for the underlying core record.
 *
 * Used by the ViewOrder, ViewInvoice, and ViewPurchaseOrder pages to
 * give admins a per-record window into Xero sync health without
 * leaving the entity's view page.
 */
trait HasXeroSyncStateInfolist
{
    /**
     * Build the "Xero sync state" infolist section.
     *
     * @param  Closure  $mirrorResolver  Receives the parent record and
     *                                   must return the Eloquent Xero
     *                                   mirror (XeroInvoice / XeroPurchaseOrder
     *                                   / etc.) or null if not synced.
     */
    public static function xeroSyncStateSection(Closure $mirrorResolver): Section
    {
        return Section::make('xero_sync_state')
            ->heading(__('laravel-crm-filament::labels.xero.sync_state'))
            ->description(__('laravel-crm-filament::labels.xero.sync_state_description'))
            ->visible(fn (?Model $record = null) => static::xeroSectionIsVisible($record))
            ->columns(3)
            ->schema([
                TextEntry::make('xero_status')
                    ->label(__('laravel-crm-filament::labels.fields.status'))
                    ->badge()
                    ->state(function (?Model $record) use ($mirrorResolver) {
                        $mirror = $record ? $mirrorResolver($record) : null;

                        return $mirror?->status ?? __('laravel-crm-filament::labels.xero.not_synced');
                    })
                    ->color(function (?Model $record) use ($mirrorResolver): string {
                        $mirror = $record ? $mirrorResolver($record) : null;

                        return $mirror ? 'success' : 'gray';
                    }),
                TextEntry::make('xero_last_sync')
                    ->label(__('laravel-crm-filament::labels.xero.last_sync'))
                    ->state(function (?Model $record) use ($mirrorResolver) {
                        $mirror = $record ? $mirrorResolver($record) : null;
                        if (! $mirror) {
                            return '—';
                        }
                        $value = $mirror->xero_updated_at ?? $mirror->updated_at;

                        return $value ? (string) $value : '—';
                    }),
                TextEntry::make('xero_id')
                    ->label(__('laravel-crm-filament::labels.xero.xero_id'))
                    ->state(function (?Model $record) use ($mirrorResolver) {
                        $mirror = $record ? $mirrorResolver($record) : null;

                        return $mirror?->xero_id ?? '—';
                    })
                    ->copyable(),
                TextEntry::make('xero_error')
                    ->label(__('laravel-crm-filament::labels.xero.error'))
                    ->state(function (?Model $record) use ($mirrorResolver) {
                        return static::xeroSyncError($record, $mirrorResolver) ?? '—';
                    })
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Return the most recent sync error for the record, if any.
     *
     * Core CRM does not currently persist a per-record Xero error
     * column, so we check both the mirror's `error` attribute (if a
     * host has added one) and an optional `xero.last_error.{morph}.{id}`
     * setting that hosts can populate from their sync pipeline.
     */
    protected static function xeroSyncError(?Model $record, Closure $mirrorResolver): ?string
    {
        if (! $record) {
            return null;
        }

        $mirror = $mirrorResolver($record);
        if ($mirror && isset($mirror->error) && $mirror->error) {
            return (string) $mirror->error;
        }

        if (! app()->bound('laravel-crm.settings')) {
            return null;
        }

        $key = sprintf('xero.last_error.%s.%s', $record->getMorphClass(), $record->getKey());

        $stored = app('laravel-crm.settings')->get($key);

        return $stored ? (string) $stored : null;
    }

    public static function xeroSectionVisibleFor(?Model $record): bool
    {
        return static::xeroSectionIsVisible($record);
    }

    public static function xeroSectionIsVisible(?Model $record): bool
    {
        if (! $record) {
            return false;
        }

        // Prefer the plugin instance bound to the currently-active panel.
        try {
            $plugin = LaravelCrmPlugin::get();
            if ($plugin) {
                return $plugin->isModuleEnabled('xero');
            }
        } catch (\Throwable) {
            // No plugin attached to the current panel — fall through.
        }

        // Fall back to the current panel's resource list (set by `register()`).
        try {
            $panel = Filament::getCurrentPanel();
            if ($panel) {
                foreach ($panel->getResources() as $resource) {
                    if ($resource === XeroContactResource::class) {
                        return true;
                    }
                }

                return false;
            }
        } catch (\Throwable) {
            // No panel at all — final fall through.
        }

        return Modules::enabled('xero');
    }
}
