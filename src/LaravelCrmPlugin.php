<?php

namespace VentureDrake\LaravelCrmFilament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use VentureDrake\LaravelCrmFilament\Livewire\SystemCheckBanner;
use VentureDrake\LaravelCrmFilament\Pages\AcceptInvitation;
use VentureDrake\LaravelCrmFilament\Pages\ActivityFeed;
use VentureDrake\LaravelCrmFilament\Pages\CalendarPage;
use VentureDrake\LaravelCrmFilament\Pages\ClickSendIntegration;
use VentureDrake\LaravelCrmFilament\Pages\Dashboard;
use VentureDrake\LaravelCrmFilament\Pages\GeneralSettings;
use VentureDrake\LaravelCrmFilament\Pages\Integrations;
use VentureDrake\LaravelCrmFilament\Pages\Reminders;
use VentureDrake\LaravelCrmFilament\Pages\TemplateSettings;
use VentureDrake\LaravelCrmFilament\Pages\Updates;
use VentureDrake\LaravelCrmFilament\Resources\Activities\ActivityResource;
use VentureDrake\LaravelCrmFilament\Resources\AddressTypes\AddressTypeResource;
use VentureDrake\LaravelCrmFilament\Resources\Calls\CallResource;
use VentureDrake\LaravelCrmFilament\Resources\Chat\ChatConversationResource;
use VentureDrake\LaravelCrmFilament\Resources\ChatWidgets\ChatWidgetResource;
use VentureDrake\LaravelCrmFilament\Resources\ContactTypes\ContactTypeResource;
use VentureDrake\LaravelCrmFilament\Resources\Customers\CustomerResource;
use VentureDrake\LaravelCrmFilament\Resources\Deals\DealResource;
use VentureDrake\LaravelCrmFilament\Resources\Deliveries\DeliveryResource;
use VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\EmailCampaignResource;
use VentureDrake\LaravelCrmFilament\Resources\EmailTemplates\EmailTemplateResource;
use VentureDrake\LaravelCrmFilament\Resources\Features\FeatureResource;
use VentureDrake\LaravelCrmFilament\Resources\FeatureStatuses\FeatureStatusResource;
use VentureDrake\LaravelCrmFilament\Resources\FieldGroups\FieldGroupResource;
use VentureDrake\LaravelCrmFilament\Resources\Fields\FieldResource;
use VentureDrake\LaravelCrmFilament\Resources\Files\FileResource;
use VentureDrake\LaravelCrmFilament\Resources\Industries\IndustryResource;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\InvoiceResource;
use VentureDrake\LaravelCrmFilament\Resources\Labels\LabelResource;
use VentureDrake\LaravelCrmFilament\Resources\Leads\LeadResource;
use VentureDrake\LaravelCrmFilament\Resources\LeadSources\LeadSourceResource;
use VentureDrake\LaravelCrmFilament\Resources\LeadStatuses\LeadStatusResource;
use VentureDrake\LaravelCrmFilament\Resources\Lunches\LunchResource;
use VentureDrake\LaravelCrmFilament\Resources\Meetings\MeetingResource;
use VentureDrake\LaravelCrmFilament\Resources\Monitors\MonitorResource;
use VentureDrake\LaravelCrmFilament\Resources\Notes\NoteResource;
use VentureDrake\LaravelCrmFilament\Resources\Orders\OrderResource;
use VentureDrake\LaravelCrmFilament\Resources\Organizations\OrganizationResource;
use VentureDrake\LaravelCrmFilament\Resources\OrganizationTypes\OrganizationTypeResource;
use VentureDrake\LaravelCrmFilament\Resources\People\PersonResource;
use VentureDrake\LaravelCrmFilament\Resources\Pipelines\PipelineResource;
use VentureDrake\LaravelCrmFilament\Resources\PipelineStageProbabilities\PipelineStageProbabilityResource;
use VentureDrake\LaravelCrmFilament\Resources\PipelineStages\PipelineStageResource;
use VentureDrake\LaravelCrmFilament\Resources\ProductAttributes\ProductAttributeResource;
use VentureDrake\LaravelCrmFilament\Resources\ProductCategories\ProductCategoryResource;
use VentureDrake\LaravelCrmFilament\Resources\Products\ProductResource;
use VentureDrake\LaravelCrmFilament\Resources\PurchaseOrders\PurchaseOrderResource;
use VentureDrake\LaravelCrmFilament\Resources\Quotes\QuoteResource;
use VentureDrake\LaravelCrmFilament\Resources\Roles\RoleResource;
use VentureDrake\LaravelCrmFilament\Resources\SmsCampaigns\SmsCampaignResource;
use VentureDrake\LaravelCrmFilament\Resources\SmsTemplates\SmsTemplateResource;
use VentureDrake\LaravelCrmFilament\Resources\Tasks\TaskResource;
use VentureDrake\LaravelCrmFilament\Resources\TaxRates\TaxRateResource;
use VentureDrake\LaravelCrmFilament\Resources\Teams\CrmTeamResource;
use VentureDrake\LaravelCrmFilament\Resources\Timezones\TimezoneResource;
use VentureDrake\LaravelCrmFilament\Resources\UserInvitations\UserInvitationResource;
use VentureDrake\LaravelCrmFilament\Resources\Users\UserResource;
use VentureDrake\LaravelCrmFilament\Resources\Xero\XeroContactResource;
use VentureDrake\LaravelCrmFilament\Resources\Xero\XeroInvoiceResource;
use VentureDrake\LaravelCrmFilament\Resources\Xero\XeroItemResource;
use VentureDrake\LaravelCrmFilament\Resources\Xero\XeroPurchaseOrderResource;
use VentureDrake\LaravelCrmFilament\Support\LogoUrl;
use VentureDrake\LaravelCrmFilament\Support\TenancyGuard;
use VentureDrake\LaravelCrmFilament\Widgets\ContactsStatsOverview;
use VentureDrake\LaravelCrmFilament\Widgets\CrmStatsOverview;
use VentureDrake\LaravelCrmFilament\Widgets\DealsPipelineValueChart;
use VentureDrake\LaravelCrmFilament\Widgets\DealStatusDoughnutChart;
use VentureDrake\LaravelCrmFilament\Widgets\DealsValueStat;
use VentureDrake\LaravelCrmFilament\Widgets\LeadsByStageChart;
use VentureDrake\LaravelCrmFilament\Widgets\LeadsVsDealsChart;
use VentureDrake\LaravelCrmFilament\Widgets\MonthlyRevenueChart;
use VentureDrake\LaravelCrmFilament\Widgets\RecentActivityList;
use VentureDrake\LaravelCrmFilament\Widgets\TasksDueTodayList;

class LaravelCrmPlugin implements Plugin
{
    /**
     * Optional module overrides. Null means "fall back to config('laravel-crm.modules')".
     *
     * @var array<string,bool>|null
     */
    protected ?array $modules = null;

    protected bool $registerDashboard = true;

    protected ?string $navigationGroup = null;

    protected ?string $brand = null;

    protected ?string $brandLogo = null;

    protected ?string $favicon = null;

    protected ?string $primaryColor = null;

    protected bool $allowUnsupportedTenancy = false;

    public function getId(): string
    {
        return 'laravel-crm';
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }

    /**
     * @param  array<string,bool>  $modules
     */
    public function modules(array $modules): static
    {
        $this->modules = $modules;

        return $this;
    }

    public function withChat(bool $enabled = true): static
    {
        $this->modules['chat'] = $enabled;

        return $this;
    }

    public function withEmailMarketing(bool $enabled = true): static
    {
        $this->modules['email-marketing'] = $enabled;

        return $this;
    }

    public function withSmsMarketing(bool $enabled = true): static
    {
        $this->modules['sms-marketing'] = $enabled;

        return $this;
    }

    public function withXero(bool $enabled = true): static
    {
        $this->modules['xero'] = $enabled;

        return $this;
    }

    public function withCustomers(bool $enabled = true): static
    {
        $this->modules['customers'] = $enabled;

        return $this;
    }

    public function withFeatures(bool $enabled = true): static
    {
        $this->modules['features'] = $enabled;

        return $this;
    }

    public function withMonitoring(bool $enabled = true): static
    {
        $this->modules['monitoring'] = $enabled;

        return $this;
    }

    /**
     * Toggle the CRM **teams** module — the `teams` entry in core's
     * `config('laravel-crm.modules')` array, which gates the CRM's own
     * `crm_teams` grouping of users.
     *
     * This is NOT `config('laravel-crm.teams')`, the separate boolean that
     * turns on core's Jetstream multi-tenancy (BelongsToTeams scoping).
     * The two are unrelated; do not conflate them.
     */
    public function withTeams(bool $enabled = true): static
    {
        $this->modules['teams'] = $enabled;

        return $this;
    }

    /**
     * Alias of {@see withTeams()}, so the module reads naturally as
     * `->teams(false)` alongside `->modules([...])`.
     */
    public function teams(bool $enabled = true): static
    {
        return $this->withTeams($enabled);
    }

    public function withDashboard(bool $enabled = true): static
    {
        $this->registerDashboard = $enabled;

        return $this;
    }

    public function navigationGroup(string $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function brand(string $brand): static
    {
        $this->brand = $brand;

        return $this;
    }

    public function brandLogo(string $url): static
    {
        $this->brandLogo = $url;

        return $this;
    }

    public function favicon(string $url): static
    {
        $this->favicon = $url;

        return $this;
    }

    public function primaryColor(string $hex): static
    {
        $this->primaryColor = $hex;

        return $this;
    }

    /**
     * Acknowledge that this panel is running with core's multi-tenancy on and
     * is not tenant-aware, silencing the runtime warning.
     *
     * Not a feature switch — nothing changes behaviourally. It exists so that
     * running anyway is a recorded decision in the panel provider rather than
     * a banner somebody stopped reading. See TenancyGuard.
     */
    public function allowUnsupportedTenancy(bool $allow = true): static
    {
        $this->allowUnsupportedTenancy = $allow;

        return $this;
    }

    public function allowsUnsupportedTenancy(): bool
    {
        return $this->allowUnsupportedTenancy;
    }

    public function isModuleEnabled(string $module): bool
    {
        if ($this->modules !== null && array_key_exists($module, $this->modules)) {
            return (bool) $this->modules[$module];
        }

        // Core CRM stores enabled modules as a flat array of slugs:
        //   config('laravel-crm.modules') === ['leads', 'deals', ...]
        return in_array($module, (array) config('laravel-crm.modules', []), true);
    }

    public function getNavigationGroup(): ?string
    {
        return $this->navigationGroup;
    }

    public function getBrand(): ?string
    {
        return $this->brand;
    }

    /**
     * The full list of Filament resource classes this plugin would register on a panel,
     * honouring the same `config('laravel-crm.modules')` gating that `register()` applies.
     *
     * Extracted so callers (e.g. install-time conflict detection) can enumerate the
     * plugin's resources without actually mutating a panel.
     *
     * @return array<int, class-string>
     */
    public function getResources(): array
    {
        // Contact + activity entities are always available (no module flag in core).
        $resources = [
            PersonResource::class,
            OrganizationResource::class,
            TaskResource::class,
            NoteResource::class,
            CallResource::class,
            MeetingResource::class,
            LunchResource::class,
            FileResource::class,
            ActivityResource::class,
        ];

        // Pipeline / marketing entities are gated by core's `laravel-crm.modules` array.
        if ($this->isModuleEnabled('leads')) {
            $resources[] = LeadResource::class;
        }

        if ($this->isModuleEnabled('deals')) {
            $resources[] = DealResource::class;
        }

        if ($this->isModuleEnabled('quotes')) {
            $resources[] = QuoteResource::class;
        }

        if ($this->isModuleEnabled('orders')) {
            $resources[] = OrderResource::class;
        }

        if ($this->isModuleEnabled('invoices')) {
            $resources[] = InvoiceResource::class;
        }

        if ($this->isModuleEnabled('deliveries')) {
            $resources[] = DeliveryResource::class;
        }

        if ($this->isModuleEnabled('purchase-orders')) {
            $resources[] = PurchaseOrderResource::class;
        }

        if ($this->isModuleEnabled('customers')) {
            $resources[] = CustomerResource::class;
        }

        // Products aren't a gated module in core; surface them whenever the panel runs.
        $resources[] = ProductResource::class;

        if ($this->isModuleEnabled('email-marketing')) {
            $resources[] = EmailCampaignResource::class;
        }

        if ($this->isModuleEnabled('sms-marketing')) {
            $resources[] = SmsCampaignResource::class;
        }

        if ($this->isModuleEnabled('chat')) {
            $resources[] = ChatConversationResource::class;
        }

        // Settings cluster lookups — always available; admins manage CRM-wide config.
        $resources[] = PipelineResource::class;
        $resources[] = PipelineStageResource::class;
        $resources[] = PipelineStageProbabilityResource::class;
        $resources[] = LeadStatusResource::class;
        $resources[] = FeatureStatusResource::class;

        // `teams` is a core module slug (config('laravel-crm.modules')), not the
        // `laravel-crm.teams` multi-tenancy boolean — see withTeams().
        if ($this->isModuleEnabled('teams')) {
            $resources[] = CrmTeamResource::class;
        }

        $resources[] = LabelResource::class;
        $resources[] = LeadSourceResource::class;
        $resources[] = TaxRateResource::class;
        $resources[] = ProductCategoryResource::class;
        $resources[] = ContactTypeResource::class;
        $resources[] = AddressTypeResource::class;
        $resources[] = OrganizationTypeResource::class;
        $resources[] = IndustryResource::class;
        $resources[] = TimezoneResource::class;
        $resources[] = ProductAttributeResource::class;
        $resources[] = FieldGroupResource::class;
        $resources[] = FieldResource::class;
        $resources[] = RoleResource::class;
        $resources[] = UserResource::class;
        $resources[] = UserInvitationResource::class;

        if ($this->isModuleEnabled('email-marketing')) {
            $resources[] = EmailTemplateResource::class;
        }

        if ($this->isModuleEnabled('sms-marketing')) {
            $resources[] = SmsTemplateResource::class;
        }

        if ($this->isModuleEnabled('chat')) {
            $resources[] = ChatWidgetResource::class;
        }

        if ($this->isModuleEnabled('xero')) {
            $resources[] = XeroContactResource::class;
            $resources[] = XeroItemResource::class;
            $resources[] = XeroInvoiceResource::class;
            $resources[] = XeroPurchaseOrderResource::class;
        }

        // Roadmap + monitoring resources are registered LAST so a class-not-found in either's
        // upstream model doesn't short-circuit the panel boot before its other resources land.
        if ($this->isModuleEnabled('features')) {
            $resources[] = FeatureResource::class;
        }

        if ($this->isModuleEnabled('monitoring')) {
            $resources[] = MonitorResource::class;
        }

        return $resources;
    }

    public function register(Panel $panel): void
    {
        $resources = $this->getResources();

        // Branding overrides: prefer plugin setters, fall back to laravel-crm settings.
        $settings = app()->bound('laravel-crm.settings') ? app('laravel-crm.settings') : null;
        $brandName = $this->brand ?? ($settings?->get('organization_name')) ?? 'Laravel Filament CRM';
        $panel->brandName($brandName);
        // A plugin-supplied brandLogo is already a URL; a `logo_file` setting is
        // a path on the `public` disk, so it has to be resolved the same way
        // Auth\Login does.
        $logo = $this->brandLogo ?? LogoUrl::resolve($settings?->get('logo_file'));
        if ($logo) {
            $panel->brandLogo($logo);
        }
        if ($this->favicon) {
            $panel->favicon($this->favicon);
        }
        // Default to the core CRM's signature teal when the host hasn't set its own.
        $panelColor = $this->primaryColor ?? '#05b3a9';
        $panel->colors(['primary' => $panelColor]);

        $panel->resources($resources);

        // Pin the visible nav-group order end-to-end. Any groups not listed here
        // (e.g. Integrations from the Xero mirrors) render after the pinned sequence.
        $panel->navigationGroups([
            'Activity',
            'Marketing',
            'Sales',
            'Contacts',
            'Roadmap',
            'Monitoring',
            'Catalog',
            'Settings',
        ]);

        $pages = [
            ActivityFeed::class,
            CalendarPage::class,
            GeneralSettings::class,
            Integrations::class,
            ClickSendIntegration::class,
            Reminders::class,
            TemplateSettings::class,
            Updates::class,
        ];

        if ($this->registerDashboard && ! $this->panelHasDashboard($panel)) {
            $pages[] = Dashboard::class;
        }

        $panel->pages($pages);

        // Widgets are registered here so they are "footer-available" on any
        // page (e.g. ViewRecord footers). The Dashboard page's getWidgets()
        // decides which ones actually render on the dashboard, applying its
        // own per-module gating for that layout.
        $widgets = [
            CrmStatsOverview::class,
            DealsValueStat::class,
            ContactsStatsOverview::class,
            LeadsByStageChart::class,
            LeadsVsDealsChart::class,
            DealsPipelineValueChart::class,
            DealStatusDoughnutChart::class,
            MonthlyRevenueChart::class,
            TasksDueTodayList::class,
            RecentActivityList::class,
        ];

        $panel->widgets($widgets);

        // A render hook, not a widget: a widget attaches per page and renders
        // inside the content grid, which is the wrong place for a system-wide
        // alert. See SystemCheckBanner.
        $panel->renderHook(
            PanelsRenderHook::CONTENT_START,
            fn (): string => Blade::render('@livewire(\'' . SystemCheckBanner::NAME . '\')'),
        );

        $panel->renderHook(
            PanelsRenderHook::BODY_START,
            fn (): string => TenancyGuard::shouldWarn($this->allowUnsupportedTenancy)
                ? Blade::render(
                    '<div class="fi-banner bg-danger-600 px-4 py-2 text-sm text-white">{{ $message }}</div>',
                    ['message' => TenancyGuard::message()],
                )
                : '',
        );

        // Registered through routes() rather than pages() so it lands in the
        // group *before* Route::middleware($panel->getAuthMiddleware()): an
        // invitee accepting an invitation is by definition not a panel user
        // yet. See AcceptInvitation and InvitationUrl.
        $panel->routes(fn (Panel $panel) => AcceptInvitation::registerPanelRoute($panel));
    }

    protected function panelHasDashboard(Panel $panel): bool
    {
        foreach ($panel->getPages() as $page) {
            if ($page === \Filament\Pages\Dashboard::class
                || is_subclass_of($page, \Filament\Pages\Dashboard::class)) {
                return true;
            }
        }

        return false;
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
