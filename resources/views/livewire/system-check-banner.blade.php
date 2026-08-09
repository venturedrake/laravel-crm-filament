{{--
    Rendered through PanelsRenderHook::CONTENT_START, so it sits above the page
    content on every panel page rather than inside one page's grid.

    Filament's <x-filament::callout>, not hand-rolled markup: this package ships
    no compiled CSS, and Filament's stylesheet carries only its own `fi-*`
    classes — a raw `class="rounded-xl border px-4 py-3"` here would resolve to
    nothing and the alert would render as bare text.
--}}
<div>
    @foreach ($messages as $message)
        <x-filament::callout
            :color="($message['level'] ?? 'info') === 'warning' ? 'warning' : 'info'"
            :icon="($message['level'] ?? 'info') === 'warning' ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-information-circle'"
        >
            {{-- The sentence is developer-authored lang; every interpolated
                 value was escaped in message(). --}}
            {!! $message['html'] !!}

            @if ($loop->first)
                <x-slot name="controls">
                    <x-filament::icon-button
                        icon="heroicon-m-x-mark"
                        color="gray"
                        size="sm"
                        wire:click="dismiss"
                        :label="__('laravel-crm-filament::labels.actions.dismiss')"
                    />
                </x-slot>
            @endif
        </x-filament::callout>
    @endforeach
</div>
