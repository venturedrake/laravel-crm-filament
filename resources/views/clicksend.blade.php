<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        @if ($this->canEditCrmSettings())
            <div style="margin-top: 1.5rem;">
                <x-filament::button type="submit">{{ __('laravel-crm-filament::labels.actions.save_changes') }}</x-filament::button>
            </div>
        @endif
    </form>
</x-filament-panels::page>
