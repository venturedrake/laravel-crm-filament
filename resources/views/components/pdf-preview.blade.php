{{--
    The preview slide-over's body.

    An <iframe> rather than a canvas: the browser's own PDF viewer already does
    paging, zoom, text selection and printing, and it renders the exact bytes
    the download button produces. See Support\DocumentPreview for why the panel
    does not reuse core's pdf.js drawer.

    Sized with an inline style, not Tailwind utilities, for the reason
    CrmBladeStylingTest exists: this package ships no CSS and Filament's compiled
    theme carries no utility layer, so `h-[80vh] w-full` resolves to nothing.
    An iframe with no height collapses to ~150px or to zero — which presents
    exactly as "the preview is not showing".

    Viewport-relative because a slide-over's body has no intrinsic height to
    inherit, so a percentage height would collapse too.
--}}
@php($src = $src ?? null)

@if ($src)
    <iframe
        src="{{ $src }}"
        title="{{ $title ?? __('laravel-crm-filament::labels.actions.preview') }}"
        class="fi-pdf-preview"
        style="display: block; width: 100%; height: 80vh; border: 0; border-radius: 0.5rem;"
    ></iframe>
@else
    <p style="font-size: 0.875rem; opacity: 0.7;">
        {{ __('laravel-crm-filament::labels.actions.preview_unavailable') }}
    </p>
@endif
