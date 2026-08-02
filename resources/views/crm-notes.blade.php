<div class="crm-card-area-notes" data-testid="crm-card-area-notes">
    @include('laravel-crm-filament::partials.crm-card-styles')

    @php
        $noteRows = $this->relatedActivityRows();
    @endphp

    @if ($editingId === null)
        <div class="crm-card-card crm-card-card--add" data-testid="crm-lead-note-add-card">
            <h3 class="crm-card-section-heading">{{ __('laravel-crm-filament::labels.sections.add_note') }}</h3>
            <hr class="crm-card-section-divider" />
            <form wire:submit="createNote" class="crm-card-form">
                <div class="crm-card-field">
                    <label class="crm-card-field-label" for="crm-lead-note-add-content">{{ __('laravel-crm-filament::labels.fields.note') }}</label>
                    <textarea
                        id="crm-lead-note-add-content"
                        class="crm-card-textarea"
                        wire:model="data.content"
                        rows="4"
                    ></textarea>
                </div>
                <div class="crm-card-field">
                    <label class="crm-card-field-label" for="crm-lead-note-add-noted-at">{{ __('laravel-crm-filament::labels.fields.noted_at') }}</label>
                    <input
                        id="crm-lead-note-add-noted-at"
                        class="crm-card-noted-at"
                        type="datetime-local"
                        wire:model="data.noted_at"
                    />
                </div>
                @error('data.content')
                    <div class="crm-card-empty" style="text-align:left;color:var(--crm-card-danger);">{{ $message }}</div>
                @enderror
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

    @forelse ($noteRows as $note)
        <div class="crm-card-card" data-note-id="{{ $note->id }}" data-testid="crm-lead-note-card">
            @if ($editingId === $note->id)
                <form wire:submit="updateNote" class="crm-card-form" data-testid="crm-lead-note-edit-form">
                    <textarea
                        class="crm-card-textarea"
                        wire:model="data.content"
                        rows="4"
                    ></textarea>
                    <input
                        class="crm-card-noted-at"
                        type="datetime-local"
                        wire:model="data.noted_at"
                    />
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
                @if ($this->isRelatedActivityRecord($note))
                    <div class="crm-card-badges">
                        @include('laravel-crm-filament::partials.crm-related-badge', ['related' => true])
                    </div>
                @endif
                <div class="crm-card-card-head">
                    <div class="crm-card-card-meta">
                        {{ $note->created_at?->diffForHumans() }} - {{ $note->createdByUser?->name }}
                    </div>
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
                                wire:click="editNote({{ $note->id }})"
                                @click="open = false"
                                class="crm-card-dropdown-item"
                                role="menuitem"
                            >Edit</button>
                            <button
                                type="button"
                                wire:click="deleteNote({{ $note->id }})"
                                wire:confirm="Delete this note?"
                                @click="open = false"
                                class="crm-card-dropdown-item crm-card-dropdown-item--danger"
                                role="menuitem"
                            >Delete</button>
                        </div>
                    </div>
                </div>
                <div class="crm-card-card-body">{{ $note->content }}</div>
                @if ($note->noted_at)
                    <div class="crm-card-card-footer">
                        <span class="crm-card-pill">Noted at {{ $note->noted_at->format('h:i A') }} on {{ $note->noted_at->format('M d, Y') }}</span>
                    </div>
                @endif
            @endif
        </div>
    @empty
        <div class="crm-card-empty">No notes yet.</div>
    @endforelse
</div>
