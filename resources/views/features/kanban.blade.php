<x-filament-panels::page>
    @php
        $statuses = $this->getStatuses();
        $featuresByStatus = $this->getFeaturesByStatus();
    @endphp

    <style>
        .crm-kanban { display: grid; gap: 1rem; overflow-x: auto; }
        .crm-kanban-col {
            background: rgba(0,0,0,0.04);
            border-radius: 0.75rem;
            padding: 0.75rem;
            min-height: 200px;
        }
        html.dark .crm-kanban-col { background: rgba(255,255,255,0.04); }
        .crm-kanban-col-head {
            display: flex; justify-content: space-between; align-items: flex-start;
            margin-bottom: 0.75rem;
        }
        .crm-kanban-col-title { font-weight: 600; font-size: 0.875rem; display: flex; align-items: center; gap: 0.375rem; }
        .crm-kanban-col-dot { display: inline-block; width: 0.5rem; height: 0.5rem; border-radius: 9999px; background: #6b7280; }
        .crm-kanban-col-meta { text-align: right; font-size: 0.75rem; color: #6b7280; line-height: 1.2; }
        .crm-kanban-list { display: flex; flex-direction: column; gap: 0.5rem; min-height: 60px; }
        .crm-kanban-card {
            position: relative;
            background: #fff;
            border: 1px solid rgba(0,0,0,0.06);
            border-radius: 0.5rem;
            padding: 0.625rem 0.75rem;
            cursor: grab;
            transition: box-shadow 120ms ease, transform 120ms ease;
        }
        html.dark .crm-kanban-card { background: rgb(31,41,55); border-color: rgba(255,255,255,0.08); }
        .crm-kanban-card:hover { box-shadow: 0 4px 10px rgba(0,0,0,0.08); }
        .crm-kanban-card-link { display: block; text-decoration: none; color: inherit; }
        .crm-kanban-card-id { font-size: 0.7rem; font-weight: 600; color: #6b7280; }
        .crm-kanban-card-title { font-size: 0.875rem; font-weight: 500; margin-top: 0.125rem; }
        .crm-kanban-card-meta { font-size: 0.7rem; color: #6b7280; margin-top: 0.25rem; }
        .crm-kanban-empty { color: #6b7280; font-size: 0.875rem; text-align: center; padding: 3rem 1rem; grid-column: 1 / -1; }
    </style>

    <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1rem;">
        <label style="font-size:0.75rem; color:#6b7280;">Owner</label>
        @if ($this->ownerSearchEnabled())
            <input type="search" wire:model.live.debounce.300ms="ownerSearch" placeholder="Search owners…" autocomplete="off"
                   style="font-size:0.875rem; padding:0.375rem 0.5rem; border-radius:0.375rem; border:1px solid #d1d5db; background:#fff;">
        @endif
        <select wire:model.live="ownerFilter"
                style="font-size:0.875rem; padding:0.375rem 0.5rem; border-radius:0.375rem; border:1px solid #d1d5db; background:#fff;">
            <option value="">Everyone</option>
            @foreach ($this->getOwners() as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>

    <div
        x-data="{
            initSortables() {
                if (typeof window.Sortable === 'undefined') return;
                this.$refs.board.querySelectorAll('[data-kanban-column]').forEach(col => {
                    new window.Sortable(col, {
                        group: 'crm-features',
                        animation: 150,
                        ghostClass: 'opacity-50',
                        onEnd: (evt) => {
                            const statusId = evt.to.getAttribute('data-status-id');
                            const featureId = evt.item.getAttribute('data-feature-id');
                            if (statusId && featureId) {
                                $wire.moveFeature(featureId, parseInt(statusId));
                            }
                        },
                    });
                });
            },
        }"
        x-init="
            if (typeof window.Sortable === 'undefined') {
                const s = document.createElement('script');
                s.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js';
                s.onload = () => initSortables();
                document.head.appendChild(s);
            } else {
                initSortables();
            }
        "
    >
        <div x-ref="board" class="crm-kanban"
             style="grid-template-columns: repeat({{ max($statuses->count(), 1) }}, minmax(260px, 1fr));">
            @forelse ($statuses as $status)
                @php $statusFeatures = $featuresByStatus[$status->id] ?? collect(); @endphp
                <div class="crm-kanban-col">
                    <div class="crm-kanban-col-head">
                        <div class="crm-kanban-col-title">
                            @if ($status->color)
                                <span class="crm-kanban-col-dot" style="background: {{ $status->color }};"></span>
                            @endif
                            {{ $status->name }}
                        </div>
                        <div class="crm-kanban-col-meta">
                            <div>{{ $statusFeatures->count() }}</div>
                        </div>
                    </div>
                    <div data-kanban-column data-status-id="{{ $status->id }}" class="crm-kanban-list">
                        @foreach ($statusFeatures as $feature)
                            <div data-feature-id="{{ $feature->external_id }}" class="crm-kanban-card">
                                <a href="{{ route('filament.crm.resources.features.edit', ['record' => $feature->external_id]) }}"
                                   class="crm-kanban-card-link">
                                    <div class="crm-kanban-card-id">{{ $feature->feature_id }}</div>
                                    <div class="crm-kanban-card-title">{{ $feature->title }}</div>
                                    @if ($feature->votes_count)
                                        <div class="crm-kanban-card-meta">
                                            {{ $feature->votes_count }} {{ __('laravel-crm-filament::labels.fields.votes') }}
                                        </div>
                                    @endif
                                </a>
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="crm-kanban-empty">
                    No feature statuses configured.
                    <a style="color:#05b3a9;" href="{{ route('filament.crm.settings.resources.feature-statuses.index') }}">
                        Configure feature statuses
                    </a>.
                </div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
