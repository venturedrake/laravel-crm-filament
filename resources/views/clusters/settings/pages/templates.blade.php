<x-filament-panels::page>
    @php
        $docTypes = $this->docTypes();
        $templates = $this->templates();
        $overridden = $this->overriddenDocTypes();
        $activeTab = in_array($this->tab, $docTypes, true) ? $this->tab : $docTypes[0];
    @endphp

    <div class="space-y-6">
        <div class="flex flex-wrap gap-2">
            @foreach ($docTypes as $docType)
                <button
                    type="button"
                    wire:click="$set('tab', '{{ $docType }}')"
                    @class([
                        'rounded-lg px-3 py-1.5 text-sm font-medium',
                        'bg-primary-600 text-white' => $activeTab === $docType,
                        'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-300' => $activeTab !== $docType,
                    ])
                >
                    {{ __('laravel-crm-filament::labels.templates.doc_type_' . str_replace('-', '_', $docType)) }}
                </button>
            @endforeach
        </div>

        @if ($overridden[$activeTab] ?? false)
            {{-- Saving writes a slug for every doc type at once, which retires a
                 published-and-edited legacy view. Say so before it happens. --}}
            <x-filament::section>
                <div class="text-sm text-warning-600">
                    {{ __('laravel-crm-filament::labels.templates.published_override_warning') }}
                </div>
            </x-filament::section>
        @endif

        <x-filament::section :heading="__('laravel-crm-filament::labels.templates.choose_a_template')">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($templates as $slug => $template)
                    @php $isSelected = ($this->selected[$activeTab] ?? null) === $slug; @endphp

                    <div @class([
                        'rounded-xl border p-3 space-y-3',
                        'border-primary-500 ring-1 ring-primary-500' => $isSelected,
                        'border-gray-200 dark:border-white/10' => ! $isSelected,
                    ])>
                        @php $thumbnail = $this->thumbnail($slug); @endphp

                        @if ($thumbnail)
                            {{-- Inlined data URI, resolved published-first through the
                                 registry: no route, so nothing to 404 on a headless host. --}}
                            <img src="{{ $thumbnail }}" alt="{{ $template['label'] }}" class="w-full rounded-lg border border-gray-100 dark:border-white/5" />
                        @endif

                        <div>
                            <div class="font-medium">{{ $template['label'] }}</div>
                            <div class="text-sm text-gray-500">{{ $template['description'] }}</div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <x-filament::button
                                size="sm"
                                :color="$isSelected ? 'primary' : 'gray'"
                                wire:click="select('{{ $activeTab }}', '{{ $slug }}')"
                            >
                                {{ $isSelected
                                    ? __('laravel-crm-filament::labels.templates.selected')
                                    : __('laravel-crm-filament::labels.templates.select') }}
                            </x-filament::button>

                            {{ ($this->previewAction)(['docType' => $activeTab, 'slug' => $slug]) }}

                            @php $externalUrl = $this->externalPreviewUrl($activeTab, $slug); @endphp
                            @if ($externalUrl)
                                <a href="{{ $externalUrl }}" target="_blank" rel="noopener noreferrer" class="text-sm text-primary-600 underline">
                                    {{ __('laravel-crm-filament::labels.templates.open_in_new_tab') }}
                                </a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        @if (static::canEditCrmSettings())
            <div>
                <x-filament::button wire:click="save">
                    {{ __('laravel-crm-filament::labels.actions.save') }}
                </x-filament::button>
            </div>
        @endif
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
