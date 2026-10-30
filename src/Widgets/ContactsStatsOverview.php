<?php

namespace VentureDrake\LaravelCrmFilament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use VentureDrake\LaravelCrm\Models\Organization;
use VentureDrake\LaravelCrm\Models\Person;

class ContactsStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = null;

    public function getHeading(): ?string
    {
        return __('laravel-crm-filament::labels.dashboard.heading_contacts')
            . ' ' . __('laravel-crm-filament::labels.dashboard.heading_period_suffix', [
                'period' => __('laravel-crm-filament::labels.sales.last_30_days'),
            ]);
    }

    /**
     * Three tiles so the row lines up with the Sales row above it; a lone
     * full-width tile left most of the row empty.
     */
    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $windowStart = now()->subDays(30)->startOfDay();
        $windowEnd = now();

        $peopleCount = Person::query()
            ->whereBetween('created_at', [$windowStart, $windowEnd])
            ->count();

        $orgsCount = Organization::query()
            ->whereBetween('created_at', [$windowStart, $windowEnd])
            ->count();

        return [
            Stat::make(
                __('laravel-crm-filament::labels.dashboard.new_contacts'),
                (string) ($peopleCount + $orgsCount),
            )->description(__('laravel-crm-filament::labels.dashboard.people_orgs_breakdown', [
                'people' => $peopleCount,
                'organizations' => $orgsCount,
            ]))->color('primary'),
            Stat::make(
                __('laravel-crm-filament::labels.sales.people'),
                (string) Person::query()->count(),
            )->description(__('laravel-crm-filament::labels.dashboard.new_count', ['count' => $peopleCount]))
                ->color('success'),
            Stat::make(
                __('laravel-crm-filament::labels.sales.organizations'),
                (string) Organization::query()->count(),
            )->description(__('laravel-crm-filament::labels.dashboard.new_count', ['count' => $orgsCount]))
                ->color('warning'),
        ];
    }
}
