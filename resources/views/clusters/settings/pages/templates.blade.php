{{--
    Everything here is a Filament component or an `fi-*` class.

    This package ships no compiled CSS, and Filament's own stylesheet contains
    only its `fi-*` classes — not a general Tailwind utility set. A raw
    `class="grid grid-cols-3 gap-4"` in a package view resolves to nothing at
    all, which is how this page came to render as a stack of unstyled text. The
    one exception is the `->grid()` attribute macro, which is Filament's own
    mechanism: it emits `fi-grid` plus inline `--cols-*` custom properties, so
    it needs no build step either.
--}}
<x-filament-panels::page>
    @php
        $docTypes = $this->docTypes();
        $templates = $this->templates();
        $overridden = $this->overriddenDocTypes();
        $activeTab = in_array($this->tab, $docTypes, true) ? $this->tab : $docTypes[0];
    @endphp

    <x-filament::tabs>
        @foreach ($docTypes as $docType)
            <x-filament::tabs.item
                :active="$activeTab === $docType"
                wire:click="$set('tab', '{{ $docType }}')"
            >
                {{ __('laravel-crm-filament::labels.templates.doc_type_' . str_replace('-', '_', $docType)) }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    @if ($overridden[$activeTab] ?? false)
        {{-- Saving writes a slug for every doc type at once, which retires a
             published-and-edited legacy view. Say so before it happens.

             The sentence goes in `description`, not the default slot: the
             callout component never echoes `$slot`, so content placed there is
             dropped and only the heading survives. --}}
        <x-filament::callout
            color="warning"
            icon="heroicon-o-exclamation-triangle"
            :heading="__('laravel-crm-filament::labels.templates.published_override_heading')"
            :description="__('laravel-crm-filament::labels.templates.published_override_warning')"
        />
    @endif

    <x-filament::section :heading="__('laravel-crm-filament::labels.templates.choose_a_template')">
        <div
            {{
                (new \Illuminate\View\ComponentAttributeBag)
                    ->grid(['default' => 1, 'md' => 2, 'xl' => 3])
                    ->class(['fi-sc-has-gap'])
            }}
        >
            @foreach ($templates as $slug => $template)
                @php
                    $isSelected = ($this->selected[$activeTab] ?? null) === $slug;
                    $thumbnail = $this->thumbnail($slug);
                    $externalUrl = $this->externalPreviewUrl($activeTab, $slug);
                @endphp

                <x-filament::section
                    compact
                    :heading="$template['label']"
                    :description="$template['description']"
                    :icon="$isSelected ? 'heroicon-o-check-circle' : null"
                    :icon-color="$isSelected ? 'primary' : 'gray'"
                >
                    @if ($thumbnail)
                        {{-- Inlined data URI, resolved published-first through the
                             registry: no route, so nothing to 404 on a headless
                             host. Sized with an inline style because a utility
                             class would not survive the missing CSS build. --}}
                        <img
                            src="{{ $thumbnail }}"
                            alt="{{ $template['label'] }}"
                            style="width: 100%; height: auto; display: block;"
                        />
                    @endif

                    <x-slot name="footer">
                        <x-filament::actions
                            :actions="[]"
                            alignment="start"
                        >
                            @if ($isSelected)
                                <x-filament::button
                                    size="sm"
                                    color="primary"
                                    icon="heroicon-m-check"
                                    disabled
                                >
                                    {{ __('laravel-crm-filament::labels.templates.selected') }}
                                </x-filament::button>
                            @else
                                <x-filament::button
                                    size="sm"
                                    color="gray"
                                    wire:click="select('{{ $activeTab }}', '{{ $slug }}')"
                                >
                                    {{ __('laravel-crm-filament::labels.templates.select') }}
                                </x-filament::button>
                            @endif

                            {{ ($this->previewAction)(['docType' => $activeTab, 'slug' => $slug]) }}

                            @if ($externalUrl)
                                <x-filament::link
                                    :href="$externalUrl"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    icon="heroicon-m-arrow-top-right-on-square"
                                    icon-position="after"
                                    size="sm"
                                >
                                    {{ __('laravel-crm-filament::labels.templates.open_in_new_tab') }}
                                </x-filament::link>
                            @endif
                        </x-filament::actions>
                    </x-slot>
                </x-filament::section>
            @endforeach
        </div>
    </x-filament::section>

    @if (static::canEditCrmSettings())
        <x-filament::actions
            :actions="[]"
            alignment="start"
        >
            <x-filament::button wire:click="save" icon="heroicon-m-check">
                {{ __('laravel-crm-filament::labels.actions.save') }}
            </x-filament::button>
        </x-filament::actions>
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
