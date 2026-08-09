<?php

namespace VentureDrake\LaravelCrmFilament\Pages;

use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use VentureDrake\LaravelCrmFilament\Concerns\ChecksCrmPermissions;
use VentureDrake\LaravelCrmFilament\LaravelCrmPlugin;
use VentureDrake\LaravelCrmFilament\Widgets\ContactsStatsOverview;
use VentureDrake\LaravelCrmFilament\Widgets\CrmStatsOverview;
use VentureDrake\LaravelCrmFilament\Widgets\DealsPipelineValueChart;
use VentureDrake\LaravelCrmFilament\Widgets\DealStatusDoughnutChart;
use VentureDrake\LaravelCrmFilament\Widgets\DealsValueStat;
use VentureDrake\LaravelCrmFilament\Widgets\LeadsVsDealsChart;
use VentureDrake\LaravelCrmFilament\Widgets\MonthlyRevenueChart;
use VentureDrake\LaravelCrmFilament\Widgets\RecentActivityList;
use VentureDrake\LaravelCrmFilament\Widgets\TasksDueTodayList;

class Dashboard extends BaseDashboard
{
    use ChecksCrmPermissions;

    /**
     * Layout mirrors the core `laravel-crm` package's /crm/dashboard exactly:
     *   Sales stats → Finance stats → Contacts stat → Revenue Trend →
     *   Pipeline Value → Leads vs Deals → Deal Status →
     *   Upcoming Tasks → Recent Activity.
     *
     * Widgets not present in core (LeadsByStageChart, CampaignPerformanceChart)
     * are intentionally omitted so the Filament dashboard stays feature-parity with
     * the Livewire dashboard.
     *
     * The Dashboard page itself stays ungated — a user who can reach the panel
     * has to land somewhere, and gating it means a 403 immediately after login.
     * The three widgets that read CRM data are not: with ActivityPolicy now
     * registered in core 2.4.0, "intentionally ungated" is no longer defensible
     * for RecentActivityList, and the same argument applies to the tasks list
     * and the contacts stat.
     *
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        $widgets = [];

        // Sales stats — CrmStatsOverview's individual stats are gated on
        // leads/deals internally; surface the widget when either module is on.
        if (static::moduleEnabled('leads') || static::moduleEnabled('deals')) {
            $widgets[] = CrmStatsOverview::class;
        }

        // Finance stats — DealsValueStat covers invoices/quotes/orders.
        if (static::moduleEnabled('invoices')
            || static::moduleEnabled('quotes')
            || static::moduleEnabled('orders')) {
            $widgets[] = DealsValueStat::class;
        }

        if (static::userCan('view crm contacts')) {
            $widgets[] = ContactsStatsOverview::class;
        }

        // Revenue Trend charts paid invoices + orders together.
        if (static::moduleEnabled('invoices') || static::moduleEnabled('orders')) {
            $widgets[] = MonthlyRevenueChart::class;
        }

        // Pipeline Value / Deal Status are deal-only.
        if (static::moduleEnabled('deals')) {
            $widgets[] = DealsPipelineValueChart::class;
        }

        // Leads vs Deals needs at least one of the two modules.
        if (static::moduleEnabled('leads') || static::moduleEnabled('deals')) {
            $widgets[] = LeadsVsDealsChart::class;
        }

        if (static::moduleEnabled('deals')) {
            $widgets[] = DealStatusDoughnutChart::class;
        }

        if (static::userCan('view crm tasks')) {
            $widgets[] = TasksDueTodayList::class;
        }

        if (static::userCan('view crm activities')) {
            $widgets[] = RecentActivityList::class;
        }

        return $widgets;
    }

    /**
     * Whether the current user holds a CRM permission.
     *
     * Uses ChecksCrmPermissions' fail-open semantics deliberately: a dashboard
     * widget is a read-only summary, and an install that never seeded these
     * permissions should keep the dashboard it has always had rather than
     * losing half of it to a silent 403.
     */
    protected static function userCan(string $permission): bool
    {
        return static::userHasCrmPermission($permission);
    }

    /**
     * Resolve whether a given module is enabled for the current panel.
     *
     * Preference order matches the previous `campaignsModuleEnabled()` helper:
     *   1. Ask the plugin instance bound to the current panel.
     *   2. Inspect the panel's registered widgets — register() applied the
     *      same gate when building the widget list.
     *   3. Fall back to the core CRM modules config.
     */
    protected static function moduleEnabled(string $module): bool
    {
        $panel = Filament::getCurrentPanel();
        if ($panel) {
            try {
                $plugin = $panel->getPlugin('laravel-crm');
                if ($plugin instanceof LaravelCrmPlugin) {
                    return $plugin->isModuleEnabled($module);
                }
            } catch (\Throwable) {
                // Panel has no plugin under this id; fall through to config check.
            }
        }

        return in_array($module, (array) config('laravel-crm.modules', []), true);
    }

    /**
     * @deprecated Use moduleEnabled('email-marketing') instead. Preserved for
     *             backwards compatibility with any host that overrode it.
     */
    protected static function campaignsModuleEnabled(): bool
    {
        return static::moduleEnabled('email-marketing');
    }
}
