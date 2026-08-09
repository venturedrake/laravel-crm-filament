<x-filament-panels::page>
    <form wire:submit="save" class="fi-sc-form">
        {{ $this->form }}

        @if ($this->canEditCrmSettings())
            <div>
                <x-filament::button type="submit">{{ __('laravel-crm-filament::labels.actions.save') }}</x-filament::button>
            </div>
        @endif
    </form>
</x-filament-panels::page>
