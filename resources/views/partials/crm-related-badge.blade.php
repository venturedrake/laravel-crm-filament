{{--
    "Related" badge for a row that was rolled up from one of the owner
    record's related contacts / person / organization rather than belonging to
    the record itself. Only rendered while core CRM's `show_related_activity`
    setting is on, because that is the only time the rollup runs at all.
    @see \VentureDrake\LaravelCrmFilament\Concerns\RollsUpRelatedActivity
--}}
@if ($related ?? false)
    <span class="crm-card-badge crm-card-badge--related" data-testid="crm-card-related-badge">{{ __('laravel-crm-filament::labels.fields.related') }}</span>
@endif
