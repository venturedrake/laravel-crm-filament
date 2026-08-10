<?php

namespace VentureDrake\LaravelCrmFilament\Livewire;

use Livewire\Component;
use VentureDrake\LaravelCrm\Services\SystemCheckService;
use VentureDrake\LaravelCrmFilament\Pages\Updates;
use VentureDrake\LaravelCrmFilament\Support\PanelSystemCheck;

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
 * It renders two checks' alerts: core's `laravel-crm.system-check` and this
 * package's own `laravel-crm-filament.system-check`, which answers the same
 * "is the database behind the code?" question for the panel's own migrations
 * and version line. They are separate alerts fixed by separate commands, so
 * they are reported separately rather than merged into one sentence.
 *
 * The dismissal key is deliberately core's exact `system_check_dismissed`
 * setting, so dismissing in either UI carries across to the other. What is
 * stored under it is a fingerprint of *both* checks — see combinedSignature().
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
        //
        // The *combined* signature, the same one resolve() compares against.
        // Storing core's alone would mean a dismissal also swallowed every
        // future panel alert, since the stored value would never change when
        // only the panel's half did.
        $signature = $this->combinedSignature();

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

            case PanelSystemCheck::PANEL_DB_UPDATE_REQUIRED:
                // The plugin's own lang namespace, not core's: core has no key
                // for this alert and never will — it does not know this package
                // exists. Same shape as the sentence above so the two read as a
                // pair when both are showing.
                return [
                    'level' => 'info',
                    'html' => __('laravel-crm-filament::labels.updates.panel_db_update_required_banner', [
                        'command' => $this->code(__('laravel-crm-filament::labels.updates.panel_db_update_command')),
                        'updates_page' => $this->link($updatesUrl, __('laravel-crm-filament::labels.updates.panel_update_database'), false),
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

        $alerts = $this->combinedAlerts();

        if (count($alerts) === 0) {
            return;
        }

        $signature = $this->combinedSignature($alerts);

        if ($signature !== null && $this->dismissedSignature($user) === $signature) {
            return;
        }

        $this->alerts = $alerts;
        $this->signature = $signature;
    }

    /**
     * Core's alerts followed by the panel's.
     *
     * Both bindings are probed rather than assumed. `laravel-crm.system-check`
     * only exists in laravel-crm 2.4+, and this banner is most useful precisely
     * on the hosts that are behind — a banner that fatals on an older core is
     * worse than one that reports only what it can see.
     *
     * Core first because its alerts describe the foundation: an
     * UPGRADE_REQUIRED there means nothing else on the page can be trusted.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function combinedAlerts(): array
    {
        $alerts = [];

        foreach (['laravel-crm.system-check', 'laravel-crm-filament.system-check'] as $binding) {
            if (! app()->bound($binding)) {
                continue;
            }

            $alerts = array_merge($alerts, app($binding)->alerts());
        }

        return $alerts;
    }

    /**
     * A fingerprint covering *both* checks.
     *
     * Not either service's own signature(): a dismissal is keyed on the alert
     * set the user actually saw, so a signature that only fingerprints half of
     * it would keep the banner hidden after the other half changed.
     *
     * Public because it is the value dismiss() persists, and a test that cannot
     * compute the expected value can only assert that dismissal stored
     * something.
     *
     * @param  array<int, array<string, mixed>>|null  $alerts
     */
    public function combinedSignature(?array $alerts = null): ?string
    {
        $alerts ??= $this->combinedAlerts();

        if (count($alerts) === 0) {
            return null;
        }

        return substr(sha1((string) json_encode($alerts)), 0, 32);
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
