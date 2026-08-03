<div class="crm-card-area-calls" data-testid="crm-card-area-calls">
    @include('laravel-crm-filament::partials.crm-card-styles')

    @php
        $callRows = $this->relatedActivityRows();
    @endphp

    @if ($editingId === null)
        <div class="crm-card-card crm-card-card--add" data-testid="crm-lead-call-add-card">
            <h3 class="crm-card-section-heading">{{ __('laravel-crm-filament::labels.sections.add_call') }}</h3>
            <hr class="crm-card-section-divider" />
            <form wire:submit="createCall" class="crm-card-form">
                {{ $this->form }}
                <hr class="crm-card-section-divider crm-card-section-divider--footer" />
                <div class="crm-card-form-actions">
                    <button
                        type="submit"
                        class="crm-card-btn crm-card-btn--primary"
                        wire:loading.attr="disabled"
                    >{{ __('laravel-crm-filament::labels.actions.save') }}</button>
                </div>
            </form>
        </div>
    @endif

    @forelse ($callRows as $call)
        <div class="crm-card-card" data-call-id="{{ $call->id }}" data-testid="crm-lead-call-card">
            @if ($editingId === $call->id)
                <form wire:submit="updateCall" class="crm-card-form" data-testid="crm-lead-call-edit-form">
                    {{ $this->form }}
                    <div class="crm-card-form-actions">
                        <button
                            type="submit"
                            class="crm-card-btn crm-card-btn--primary"
                            wire:loading.attr="disabled"
                        >{{ __('laravel-crm-filament::labels.actions.save') }}</button>
                        <button
                            type="button"
                            wire:click="cancelEdit"
                            class="crm-card-btn"
                        >{{ __('laravel-crm-filament::labels.actions.cancel') }}</button>
                    </div>
                </form>
            @else
                @php
                    // Rolled-up rows belong to a related contact; the
                    // owner-scoped handlers cannot act on them, so they render
                    // read-only. @see RollsUpRelatedActivity
                    $isRelated = $this->isRelatedActivityRecord($call);
                @endphp
                <div class="crm-card-card-head">
                    <div class="crm-card-card-title">{{ $call->name }}</div>
                    @unless ($isRelated)
                        <div
                            x-data="{ open: false }"
                            @click.outside="open = false"
                            class="crm-card-dropdown"
                        >
                            <button
                                type="button"
                                @click="open = !open"
                                class="crm-card-dropdown-btn"
                                aria-haspopup="menu"
                                aria-expanded="false"
                                x-bind:aria-expanded="open ? 'true' : 'false'"
                            >&hellip;</button>
                            <div
                                x-show="open"
                                x-cloak
                                class="crm-card-dropdown-menu"
                                role="menu"
                            >
                                <button
                                    type="button"
                                    wire:click="editCall({{ $call->id }})"
                                    @click="open = false"
                                    class="crm-card-dropdown-item"
                                    role="menuitem"
                                >{{ __('laravel-crm-filament::labels.actions.edit') }}</button>
                                <button
                                    type="button"
                                    wire:click="deleteCall({{ $call->id }})"
                                    wire:confirm="Delete this call?"
                                    @click="open = false"
                                    class="crm-card-dropdown-item crm-card-dropdown-item--danger"
                                    role="menuitem"
                                >{{ __('laravel-crm-filament::labels.actions.delete') }}</button>
                            </div>
                        </div>
                    @endunless
                </div>
                <div class="crm-card-badges">
                    @include('laravel-crm-filament::partials.crm-related-badge', ['related' => $isRelated])
                    @if ($call->start_at)
                        <span class="crm-card-pill">{{ __('laravel-crm-filament::labels.money.start_at') }} {{ $call->start_at->format('h:i A') }} on {{ $call->start_at->format('M d, Y') }}</span>
                    @endif
                    @if ($call->finish_at)
                        <span class="crm-card-pill">{{ __('laravel-crm-filament::labels.money.finish_at') }} {{ $call->finish_at->format('h:i A') }} on {{ $call->finish_at->format('M d, Y') }}</span>
                    @endif
                </div>

                <hr class="crm-card-section-divider crm-card-section-divider--inset" />
                <h4 class="crm-card-section-title">{{ __('laravel-crm-filament::labels.fields.guests') }}</h4>
                @php
                    $guestContacts = $call->contacts->filter(fn ($c) => $c->entityable !== null);
                @endphp
                @if ($guestContacts->count() > 0)
                    <div class="crm-card-guests">
                        @foreach ($guestContacts as $guest)
                            <span class="crm-card-guest-item">
                                <span class="crm-card-guest-icon" aria-hidden="true">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20"><path d="M12 12a5 5 0 100-10 5 5 0 000 10zm0 2c-3.33 0-10 1.67-10 5v3h20v-3c0-3.33-6.67-5-10-5z"/></svg>
                                </span>
                                <span class="crm-card-guest-link">{{ $guest->entityable->name }}</span>
                            </span>
                        @endforeach
                    </div>
                @endif

                <hr class="crm-card-section-divider crm-card-section-divider--inset" />
                <h4 class="crm-card-section-title">{{ __('laravel-crm-filament::labels.fields.location') }}</h4>
                @if ($call->location)
                    <div class="crm-card-section-content">{{ $call->location }}</div>
                @endif

                <hr class="crm-card-section-divider crm-card-section-divider--inset" />
                <h4 class="crm-card-section-title">{{ __('laravel-crm-filament::labels.fields.description') }}</h4>
                @if ($call->description)
                    <div class="crm-card-section-content">{{ $call->description }}</div>
                @endif
            @endif
        </div>
    @empty
        <div class="crm-card-empty">No calls yet.</div>
    @endforelse
</div>
