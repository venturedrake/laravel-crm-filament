<?php

namespace VentureDrake\LaravelCrmFilament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use VentureDrake\LaravelCrm\Support\PdfTemplateRegistry;
use VentureDrake\LaravelCrmFilament\Concerns\AuthorizesCrmSettingsPage;
use VentureDrake\LaravelCrmFilament\Support\PdfTemplatePreview;

/**
 * Settings → Templates: the PDF template picker.
 *
 * Everything is served from the page itself — no routes. Core's preview and
 * thumbnail routes (`routes.php:1309-1320`) sit inside the
 * `laravel-crm.user_interface` gate and behind `auth.laravel-crm`, so they
 * work in a test suite that turns that flag on and 404 for exactly the
 * headless hosts this plugin serves. The preview is a Filament Action
 * returning `application/pdf` (panel-authenticated for free) and the
 * thumbnails are inlined data URIs read through the registry.
 *
 * The setting keys are byte-compatible with core's — `pdf_template_{docType}`,
 * hyphen intact for `purchase-order` — so the two UIs read and write the same
 * rows.
 */
class TemplateSettings extends Page
{
    use AuthorizesCrmSettingsPage;

    protected static string $crmPermission = 'view crm settings';

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-document-text';

    protected static string | \UnitEnum | null $navigationGroup = 'Settings';

    protected static ?string $title = 'Templates';

    protected static ?string $slug = 'templates';

    /**
     * Directly below General settings (10) and above Roles (20) — Templates is
     * a settings screen an operator reaches for alongside branding, not
     * something to hunt for at the bottom of the group.
     */
    protected static ?int $navigationSort = 15;

    protected string $view = 'laravel-crm-filament::clusters.settings.pages.templates';

    /**
     * Per-doc-type selected template slug, keyed by
     * PdfTemplateRegistry::DOC_TYPES.
     *
     * @var array<string, string>
     */
    public array $selected = [];

    /**
     * The visible doc-type tab, query-string-synced so `?tab=order` deep-links.
     */
    #[Url]
    public string $tab = 'invoice';

    public function mount(): void
    {
        $settings = app('laravel-crm.settings');
        $default = PdfTemplateRegistry::defaultSlug();

        foreach (PdfTemplateRegistry::DOC_TYPES as $docType) {
            $this->selected[$docType] = $settings->get(static::settingKey($docType), $default);
        }
    }

    public function select(string $docType, string $slug): void
    {
        if (! in_array($docType, PdfTemplateRegistry::DOC_TYPES, true)) {
            return;
        }

        if (! array_key_exists($slug, PdfTemplateRegistry::all())) {
            return;
        }

        $this->selected[$docType] = $slug;
    }

    /**
     * Write all five keys together, exactly as core's component does — the
     * form presents one shared Save, so a partial write would leave the page
     * describing a state the database is not in.
     */
    public function save(): void
    {
        // A `view crm settings`-only user can read this page but must not
        // persist anything — same split core makes.
        $this->authorizeCrmSettingsEdit();

        $settings = app('laravel-crm.settings');

        foreach (PdfTemplateRegistry::DOC_TYPES as $docType) {
            $slug = $this->selected[$docType] ?? PdfTemplateRegistry::defaultSlug();

            if (! array_key_exists($slug, PdfTemplateRegistry::all())) {
                $slug = PdfTemplateRegistry::defaultSlug();
            }

            $settings->set(static::settingKey($docType), $slug);
        }

        $settings->forgetCache();

        Notification::make()
            ->title(ucfirst(trans('laravel-crm::lang.settings_updated')))
            ->success()
            ->send();
    }

    /**
     * Stream the preview inline as application/pdf.
     *
     * An Action rather than a route: it inherits the panel's authentication,
     * so there is nothing to register and nothing that can 404 on a headless
     * install.
     */
    public function previewAction(): Action
    {
        return Action::make('preview')
            ->label(__('laravel-crm-filament::labels.templates.preview'))
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->action(function (array $arguments): ?StreamedResponse {
                abort_unless(static::canAccess(), 403);

                $docType = (string) ($arguments['docType'] ?? '');
                $slug = (string) ($arguments['slug'] ?? '');

                $pdf = PdfTemplatePreview::render($docType, $slug);

                if ($pdf === null) {
                    Notification::make()
                        ->title(__('laravel-crm-filament::labels.templates.preview_unavailable'))
                        ->danger()
                        ->send();

                    return null;
                }

                return Response::streamDownload(
                    fn () => print ($pdf),
                    PdfTemplatePreview::filename($docType, $slug),
                    ['Content-Type' => 'application/pdf'],
                    // The 4th argument, not a Content-Disposition header:
                    // streamDownload() builds its own disposition from this
                    // parameter *after* applying $headers, so a header set here
                    // is silently overwritten with the default `attachment` and
                    // "preview" downloads a file instead of rendering one.
                    'inline',
                );
            });
    }

    /**
     * Doc types currently rendering through a legacy view the host published
     * and edited, rather than through anything on this page.
     *
     * Surfaced because Save writes a slug for every doc type at once, which
     * retires that customisation — an admin who only came to look should not
     * discover that by comparing invoices afterwards.
     *
     * @return array<string, bool>
     */
    public function overriddenDocTypes(): array
    {
        $overridden = [];

        foreach (PdfTemplateRegistry::DOC_TYPES as $docType) {
            $overridden[$docType] = PdfTemplateRegistry::publishedOverrideView($docType) !== null
                && app('laravel-crm.settings')->get(static::settingKey($docType)) === null;
        }

        return $overridden;
    }

    /**
     * @return array<string, array{slug:string, label:string, description:string, thumbnail:string}>
     */
    public function templates(): array
    {
        return PdfTemplateRegistry::all();
    }

    /**
     * @return array<int, string>
     */
    public function docTypes(): array
    {
        return PdfTemplateRegistry::DOC_TYPES;
    }

    public function thumbnail(string $slug): ?string
    {
        return PdfTemplatePreview::thumbnail($slug);
    }

    /**
     * Optional "open in a new tab", offered only when core's route is actually
     * registered — the PortalUrl idiom.
     */
    public function externalPreviewUrl(string $docType, string $slug): ?string
    {
        if (! Route::has('laravel-crm.settings.templates.preview')) {
            return null;
        }

        return route('laravel-crm.settings.templates.preview', [
            'docType' => $docType,
            'slug' => $slug,
        ]);
    }

    /**
     * Byte-compatible with core's key convention — the hyphen in
     * `pdf_template_purchase-order` is load-bearing.
     */
    public static function settingKey(string $docType): string
    {
        return PdfTemplateRegistry::settingKey($docType);
    }
}
