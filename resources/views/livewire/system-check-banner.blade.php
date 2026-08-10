{{--
    Rendered through PanelsRenderHook::CONTENT_START, so it sits above the page
    content on every panel page rather than inside one page's grid.

    Filament's <x-filament::callout>, not hand-rolled markup: this package ships
    no compiled CSS, and Filament's stylesheet carries only its own `fi-*`
    classes — a raw `class="rounded-xl border px-4 py-3"` here would resolve to
    nothing and the alert would render as bare text.

    The sentence goes in `description`, NOT in the default slot. The callout
    component — the same file in Filament v4 and v5 — renders only its `icon`,
    `heading`, `description`, `footer` and `controls`; `$slot` is never echoed
    at all. Content placed there is silently dropped, which rendered the banner
    as an icon and a dismiss button with nothing between them.

    HtmlString because `description` is echoed through `{{ }}`, and `e()` passes
    an Htmlable straight through. The sentence is developer-authored lang and
    every value interpolated into it was escaped in message().

    Spacing is an inline style, not `mb-6`/`gap-3`: this package ships no CSS
    and Filament's stylesheet carries only its own `fi-*` classes, so a utility
    class here resolves to nothing (see CrmBladeStylingTest).

    The <div> stays even with nothing to show — Livewire requires a single root
    element — but it carries the margins only when it has content, or every
    page in the panel would open with an empty gap above it.
--}}
<div @if ($messages !== []) style="display: flex; flex-direction: column; gap: 0.75rem; margin-block: 1.5rem;" @endif>
    @foreach ($messages as $message)
        <x-filament::callout
            :color="($message['level'] ?? 'info') === 'warning' ? 'warning' : 'info'"
            :icon="($message['level'] ?? 'info') === 'warning' ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-information-circle'"
            :description="new \Illuminate\Support\HtmlString($message['html'] ?? '')"
        >
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
