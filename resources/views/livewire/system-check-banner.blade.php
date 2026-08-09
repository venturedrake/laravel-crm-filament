{{-- Rendered through PanelsRenderHook::CONTENT_START, so it sits above the
     page content on every panel page rather than inside one page's grid. --}}
<div>
    @if (count($messages))
        <div class="mb-4 space-y-2">
            @foreach ($messages as $message)
                <div @class([
                    'flex items-start gap-3 rounded-xl border px-4 py-3 text-sm',
                    'border-warning-300 bg-warning-50 text-warning-800 dark:border-warning-700 dark:bg-warning-400/10 dark:text-warning-300' => ($message['level'] ?? 'info') === 'warning',
                    'border-info-300 bg-info-50 text-info-800 dark:border-info-700 dark:bg-info-400/10 dark:text-info-300' => ($message['level'] ?? 'info') !== 'warning',
                ])>
                    <div class="flex-1">
                        {{-- The sentence is developer-authored lang; every
                             interpolated value was escaped in message(). --}}
                        {!! $message['html'] !!}
                    </div>

                    @if ($loop->first)
                        <button
                            type="button"
                            wire:click="dismiss"
                            class="shrink-0 rounded-lg px-2 py-1 text-xs font-medium underline"
                        >
                            {{ __('laravel-crm-filament::labels.actions.dismiss') }}
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
