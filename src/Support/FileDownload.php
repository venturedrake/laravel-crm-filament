<?php

namespace VentureDrake\LaravelCrmFilament\Support;

use Filament\Facades\Filament;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;
use VentureDrake\LaravelCrm\Models\File;
use VentureDrake\LaravelCrmFilament\Http\Controllers\FileDownloadController;

/**
 * The URL a CRM attachment downloads from.
 *
 * Attachments used to download through `temporaryUrl()`, falling back to
 * `url()`. That only works on a disk that serves files: since core 2.7.1 the
 * default is the private `local` disk, where `url()` is a `/storage` link that
 * 404s (and where it does not 404, the file is public). So downloads go through
 * {@see FileDownloadController} instead: a panel route behind the panel's auth
 * middleware, re-checked against core's FilePolicy per file, streaming from
 * whichever disk the row records. Legacy rows on `public` work the same way.
 */
class FileDownload
{
    /**
     * The panel-relative route name. Registered per panel through
     * `Panel::authenticatedRoutes()`, so the resolved name is
     * `filament.{panel}.laravel-crm-filament.file-download`.
     */
    public const ROUTE = 'laravel-crm-filament.file-download';

    /**
     * The disk a File row lives on. Rows written before the panel recorded a
     * disk fall back to the old upload disk, which is where they were written.
     */
    public static function disk(File $file): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($file->disk ?: config('laravel-crm.upload_disk', 'public'));

        return $disk;
    }

    /**
     * Whether the current panel registers the download route.
     */
    public static function available(): bool
    {
        return Filament::getCurrentOrDefaultPanel() !== null
            && app('router')->has(self::routeName());
    }

    /**
     * The route URL for a file, or null when the row has no stored path or the
     * route is not registered on this panel.
     */
    public static function urlFor(File $file): ?string
    {
        if (! $file->file || ! self::available()) {
            return null;
        }

        // The route binds on external_id. Rows the Lead/Deal files card wrote
        // before uploads went through AttachmentStore never got one; give them
        // one now rather than routing by the enumerable primary key.
        if (blank($file->external_id)) {
            // Quietly and without touching updated_at: rendering a link is
            // not an edit, and should not show up as one.
            $file->timestamps = false;
            $file->forceFill(['external_id' => Uuid::uuid4()->toString()])->saveQuietly();
            $file->timestamps = true;
        }

        return route(self::routeName(), ['file' => $file->external_id]);
    }

    /**
     * The fully-qualified route name for the current panel.
     */
    public static function routeName(): string
    {
        $panel = Filament::getCurrentOrDefaultPanel();

        return 'filament.' . ($panel?->getId() ?? 'admin') . '.' . self::ROUTE;
    }
}
