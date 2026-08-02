<div class="crm-card-area-files" data-testid="crm-card-area-files">
    @include('laravel-crm-filament::partials.crm-card-styles')

    @php
        $fileRows = $this->relatedActivityRows();
    @endphp

    <div class="crm-card-card crm-card-card--add" data-testid="crm-lead-file-add-card">
        <h3 class="crm-card-section-heading">{{ __('laravel-crm-filament::labels.sections.add_file') }}</h3>
        <hr class="crm-card-section-divider" />
        <form wire:submit="createFile" class="crm-card-form">
            {{ $this->form }}
            <hr class="crm-card-section-divider crm-card-section-divider--footer" />
            <div class="crm-card-form-actions">
                <button
                    type="submit"
                    class="crm-card-btn crm-card-btn--primary"
                    wire:loading.attr="disabled"
                >{{ __('laravel-crm-filament::labels.actions.upload') }}</button>
            </div>
        </form>
    </div>

    @forelse ($fileRows as $file)
        @php
            $downloadUrl = $this->downloadFile($file->id);
            $size = (int) ($file->filesize ?? 0);
            if ($size >= 1024 * 1024) {
                $formattedSize = round($size / (1024 * 1024), 2) . ' MB';
            } elseif ($size >= 1024) {
                $formattedSize = round($size / 1024, 2) . ' KB';
            } else {
                $formattedSize = $size . ' B';
            }
        @endphp
        <div class="crm-card-card" data-file-id="{{ $file->id }}" data-testid="crm-lead-file-card">
            <div class="crm-card-card-head">
                <div class="crm-card-card-title">
                    @if ($downloadUrl)
                        <a
                            href="{{ $downloadUrl }}"
                            target="_blank"
                            rel="noopener"
                            class="crm-card-card-title-link"
                        >{{ $file->name ?? $file->file }}</a>
                    @else
                        {{ $file->name ?? $file->file }}
                    @endif
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
                        @if ($downloadUrl)
                            <a
                                href="{{ $downloadUrl }}"
                                target="_blank"
                                rel="noopener"
                                @click="open = false"
                                class="crm-card-dropdown-item"
                                role="menuitem"
                            >{{ __('laravel-crm-filament::labels.actions.download') }}</a>
                        @endif
                        <button
                            type="button"
                            wire:click="deleteFile({{ $file->id }})"
                            wire:confirm="Delete this file?"
                            @click="open = false"
                            class="crm-card-dropdown-item crm-card-dropdown-item--danger"
                            role="menuitem"
                        >{{ __('laravel-crm-filament::labels.actions.delete') }}</button>
                    </div>
                </div>
            </div>
            <div class="crm-card-badges">
                @include('laravel-crm-filament::partials.crm-related-badge', ['related' => $this->isRelatedActivityRecord($file)])
                @if ($file->mime)
                    <span class="crm-card-pill">{{ $file->mime }}</span>
                @endif
                @if ($file->filesize)
                    <span class="crm-card-pill">{{ $formattedSize }}</span>
                @endif
            </div>
            <div class="crm-card-card-attribution">
                <small>{{ $file->created_at?->diffForHumans() }}
                    @if ($file->createdByUser)
                        &mdash; {{ $file->createdByUser->name }}
                    @endif
                </small>
            </div>
        </div>
    @empty
        <div class="crm-card-empty">No files yet.</div>
    @endforelse
</div>
