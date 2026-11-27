<?php

namespace VentureDrake\LaravelCrmFilament\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use VentureDrake\LaravelCrm\Models\File;
use VentureDrake\LaravelCrmFilament\Support\FileDownload;

/**
 * Streams a CRM attachment to a panel user who may view it.
 *
 * Attachments live on a private disk since core 2.7.1, so there is no public
 * URL to hand the browser. This route sits inside the panel's auth middleware,
 * which establishes *a* panel user; it re-checks core's FilePolicy per file
 * because being a panel user is not the same as being allowed to read this
 * file. The File is resolved by `external_id` under core's team scope.
 *
 * The response is always an `attachment`, whatever the stored type: a legacy
 * row may hold an `.html` or `.svg` uploaded before type checks existed, and
 * rendering one inline on the panel's origin would be stored XSS.
 */
class FileDownloadController
{
    public function __invoke(string $file): StreamedResponse
    {
        $record = File::query()
            ->where('external_id', $file)
            ->firstOrFail();

        abort_if(auth()->user()?->cannot('view', $record) ?? true, Response::HTTP_FORBIDDEN);

        $storage = FileDownload::disk($record);

        abort_unless(filled($record->file) && $storage->exists($record->file), Response::HTTP_NOT_FOUND);

        // Content-Disposition refuses `/` and `\` in a filename.
        $name = str_replace(['/', '\\'], '-', $record->name ?: basename($record->file));

        return $storage->download($record->file, $name, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
