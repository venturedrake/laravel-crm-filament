<?php

namespace VentureDrake\LaravelCrmFilament\Livewire;

use Livewire\Component;
use VentureDrake\LaravelCrm\Services\SystemCheckService;
use VentureDrake\LaravelCrmFilament\Pages\Updates;

/**
 * The "your install needs attention" banner, rendered into the panel through
 * Filament's CONTENT_START render hook.
 *
 * Not a Filament widget: a widget attaches per page and renders inside the
 * content grid, which is the wrong place for a system-wide alert. Not core's
 * own SystemCheckBanner either — its message() calls
 * `route('laravel-crm.updates.index')`, which does not exist on a panel-only
 * install, and its Mary blade renders unstyled inside a Filament panel.
 *
 * The dismissal key is deliberately core's exact `system_check_dismissed`
 * setting, so dismissing in either UI carries across to the other.
 */
class SystemCheckBanner extends Component
{
    /**
     * Core's per-user crm_settings row holding the signature of the alert set
     * the user last dismissed. Same name on purpose — see the class docblock.
     */
    public const DISMISS_SETTING = 'system_check_dismissed';

    /**
     * The permission this banner is gated on.
     *
     * `can()`, and specifically *not* the plugin's ChecksCrmPermissions trait,
     * which fails open. For a settings page failing open means "show the
     * page"; here it would mean showing a scary system alert to someone with
     * no way to act on it.
     */
    public const PERMISSION = 'view crm updates';

    /**
     * The Livewire alias the CONTENT_START render hook renders by name.
     */
    public const NAME = 'laravel-crm-filament.system-check-banner';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $alerts = [];

    public ?string $signature = null;

    public function mount(): void
    {
        $this->resolve();
    }

    /**
     * Record the current signature so this exact alert set stays hidden. A
     * later change to the underlying alerts produces a different signature,
     * which no longer matches, so the banner comes back.
     */
    public function dismiss(): void
    {
        $user = auth()->user();

        // Every public Livewire method is invokable straight from the client,
        // so the permission is re-checked here rather than assumed from the
        // component having rendered.
        abort_unless($user && $user->can(self::PERMISSION), 403);

        // Recomputed server-side rather than persisting $this->signature: the
        // property is client-writable, so trusting it would let a caller pin
        // the banner shut forever by posting any string they like.
        $signature = app('laravel-crm.system-check')->signature();

        if ($signature !== null) {
            app('laravel-crm.settings')->setForUser($user->getKey(), self::DISMISS_SETTING, $signature);
        }

        $this->alerts = [];
        $this->signature = null;
    }

    public function render()
    {
        return view('laravel-crm-filament::livewire.system-check-banner', [
            'messages' => array_map(fn (array $alert) => $this->message($alert), $this->alerts),
        ]);
    }

    /**
     * The alert's sentence, links substituted and every interpolated value
     * escaped. Composed here rather than in Blade so the e() calls sit next to
     * the values they protect: the sentence is developer-authored lang, but
     * the version numbers come from the database.
     */
    protected function message(array $alert): array
    {
        $docsUrl = (string) config('laravel-crm.docs_url');
        $upgradeGuideUrl = (string) config('laravel-crm.upgrade_guide_url');
        $updatesUrl = static::updatesUrl();

        switch ($alert['type'] ?? null) {
            case SystemCheckService::UPGRADE_REQUIRED:
                return [
                    'level' => 'warning',
                    'html' => __('laravel-crm::lang.system_check_upgrade_required', [
                        'guide' => $this->link($upgradeGuideUrl, __('laravel-crm::lang.system_check_upgrade_guide')),
                    ]),
                ];

            case SystemCheckService::UPDATE_AVAILABLE:
                return [
                    'level' => 'warning',
                    'html' => __('laravel-crm::lang.system_check_update_available', [
                        'details' => $this->link($docsUrl, __('laravel-crm::lang.system_check_view_version_details', [
                            'version' => $alert['latest_version'] ?? '',
                        ])),
                        'update' => $this->link($updatesUrl, __('laravel-crm::lang.system_check_update_now'), false),
                    ]),
                ];

            case SystemCheckService::DB_UPDATE_REQUIRED:
                // The command itself, not just a link to a page about it — the
                // fix is a line typed into a terminal, and the operator reading
                // this banner is the person who has to type it.
                return [
                    'level' => 'info',
                    'html' => __('laravel-crm::lang.system_check_db_update_required', [
                        'command' => $this->code(__('laravel-crm::lang.system_check_db_update_command')),
                        'updates_page' => $this->link($updatesUrl, __('laravel-crm::lang.system_check_update_database'), false),
                    ]),
                ];
        }

        return ['level' => 'info', 'html' => ''];
    }

    /**
     * The panel's own Updates page, never core's `laravel-crm.updates.index` —
     * that route does not exist on a headless install, which is exactly the
     * kind of host this banner is most useful to.
     */
    public static function updatesUrl(): string
    {
        try {
            return Updates::getUrl();
        } catch (\Throwable) {
            return '#';
        }
    }

    protected function resolve(): void
    {
        $this->alerts = [];
        $this->signature = null;

        $user = auth()->user();

        if (! $user || ! $user->can(self::PERMISSION)) {
            return;
        }

        $systemCheck = app('laravel-crm.system-check');

        $alerts = $systemCheck->alerts();

        if (count($alerts) === 0) {
            return;
        }

        $signature = $systemCheck->signature();

        if ($signature !== null && $this->dismissedSignature($user) === $signature) {
            return;
        }

        $this->alerts = $alerts;
        $this->signature = $signature;
    }

    protected function dismissedSignature($user): ?string
    {
        $dismissed = app('laravel-crm.settings')->getForUser($user->getKey(), self::DISMISS_SETTING);

        return $dismissed === null ? null : (string) $dismissed;
    }

    protected function link(string $href, string $label, bool $external = true): string
    {
        $target = $external ? ' target="_blank" rel="noopener noreferrer"' : '';

        return '<a href="' . e($href) . '"' . $target . ' class="underline font-medium">' . e($label) . '</a>';
    }

    protected function code(string $command): string
    {
        return '<code class="font-mono text-sm">' . e($command) . '</code>';
    }
}
