<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section :heading="__('laravel-crm-filament::labels.updates.installed_version')">
            <div class="text-lg font-semibold">{{ $this->currentVersion ?: '—' }}</div>

            @if ($this->installId)
                <div class="text-sm text-gray-500 mt-1">
                    {{ __('laravel-crm-filament::labels.updates.install_id') }}:
                    <span class="font-mono">{{ $this->installId }}</span>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('laravel-crm-filament::labels.updates.latest_available')">
            @if ($this->latestVersion)
                <div class="text-lg font-semibold">{{ $this->latestVersion }}</div>
                @if ($this->isUpToDate)
                    <div class="text-sm text-success-600 mt-1">
                        {{ __('laravel-crm-filament::labels.updates.up_to_date') }}
                    </div>
                @else
                    <div class="text-sm text-warning-600 mt-1">
                        {{ __('laravel-crm-filament::labels.updates.newer_available') }}
                    </div>
                @endif
            @else
                <div class="text-sm text-gray-500">
                    {{ __('laravel-crm-filament::labels.updates.no_version_information') }}
                </div>
            @endif

            {{-- version_latest_notes used to be rendered here unescaped. Nothing
                 in core writes that setting, and its only conceivable writer is a
                 remote HTTP response, so it is gone rather than escaped: a raw
                 HTML sink fed by a third-party API is not worth keeping for a
                 feature that never shipped. --}}
        </x-filament::section>

        @if ($this->needsDbUpdate)
            <x-filament::section :heading="__('laravel-crm-filament::labels.updates.database_update_required')">
                <div class="text-sm text-warning-600">
                    {{ __('laravel-crm-filament::labels.updates.database_update_required_body') }}
                </div>
            </x-filament::section>
        @endif

        <x-filament::section :heading="__('laravel-crm-filament::labels.updates.how_to_update')">
            <div class="space-y-3 text-sm">
                <p>{{ __('laravel-crm-filament::labels.updates.how_to_update_intro') }}</p>

                <pre class="rounded-lg bg-gray-950/5 dark:bg-white/5 p-3 font-mono text-xs overflow-x-auto">composer update venturedrake/laravel-crm venturedrake/laravel-crm-filament
php artisan laravelcrm:update</pre>

                <p>
                    <a
                        href="{{ config('laravel-crm.upgrade_guide_url') }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-primary-600 underline"
                    >{{ __('laravel-crm-filament::labels.updates.upgrade_guide') }}</a>
                </p>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
