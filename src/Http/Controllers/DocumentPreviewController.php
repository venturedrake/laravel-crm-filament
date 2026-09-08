<?php

namespace VentureDrake\LaravelCrmFilament\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use VentureDrake\LaravelCrmFilament\Support\CrmPdf;
use VentureDrake\LaravelCrmFilament\Support\DocumentPreview;

/**
 * Serves a document's generated PDF inline, for the preview slide-over's
 * iframe to render.
 *
 * Inline rather than attachment is the entire point: `attachment` is what the
 * download button already does, and an iframe pointed at one gets a download
 * prompt instead of a rendered page.
 *
 * Two things are deliberate about how it authorises:
 *
 *  - `$type` arrives from the browser, so it is resolved through
 *    {@see DocumentPreview::modelFor()} — a whitelist — rather than being
 *    treated as a class name. An unknown slug is a 404, not a 500.
 *  - The record is re-checked against the `view` policy here rather than
 *    trusting that the caller reached it from a table row they could see. The
 *    route sits inside the panel's auth middleware, which establishes *a* panel
 *    user, not that this user may read this record. Preview grants exactly what
 *    the download twin grants and nothing more.
 */
class DocumentPreviewController
{
    public function __invoke(string $type, string $record): StreamedResponse
    {
        $model = DocumentPreview::modelFor($type);

        abort_if($model === null, Response::HTTP_NOT_FOUND);

        $document = $model::query()
            ->where('external_id', $record)
            ->firstOrFail();

        abort_if(auth()->user()?->cannot('view', $document) ?? true, Response::HTTP_FORBIDDEN);

        $path = storage_path(CrmPdf::renderToDisk($document, $type));
        $filename = CrmPdf::filename($document, $type);

        // streamDownload() rather than Response::file(): the PDF is generated
        // per request into testbench-and-host-writable storage, and streaming
        // lets the response start without the framework stat-ing a path the
        // caller never sees. The disposition is what the iframe needs.
        return response()->streamDownload(
            function () use ($path): void {
                echo (string) file_get_contents($path);
            },
            $filename,
            [
                'Content-Type' => 'application/pdf',
                // A generated document is per-record and can change on any
                // edit; a cached preview showing yesterday's total is worse
                // than a re-render.
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
            'inline',
        );
    }
}
