<x-filament-panels::page>
    @php
        $stages = $this->getStages();
        $quotesByStage = $this->getQuotesByStage();
        $currency = config('laravel-crm.default_currency', 'USD');
    @endphp

    <style>
        .crm-kanban { display: grid; gap: 1rem; overflow-x: auto; }
        .crm-kanban-col { background: rgba(0,0,0,0.04); border-radius: 0.75rem; padding: 0.75rem; min-height: 200px; }
        html.dark .crm-kanban-col { background: rgba(255,255,255,0.04); }
        .crm-kanban-col-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem; }
        .crm-kanban-col-title { font-weight: 600; font-size: 0.875rem; }
        .crm-kanban-col-meta { text-align: right; font-size: 0.75rem; color: #6b7280; line-height: 1.2; }
        .crm-kanban-col-total { font-size: 0.65rem; color: #9ca3af; margin-top: 0.125rem; }
        .crm-kanban-list { display: flex; flex-direction: column; gap: 0.5rem; min-height: 60px; }
        .crm-kanban-card { position: relative; background: #fff; border: 1px solid rgba(0,0,0,0.06); border-radius: 0.5rem; padding: 0.625rem 0.75rem; cursor: grab; transition: box-shadow 120ms ease; }
        html.dark .crm-kanban-card { background: rgb(31,41,55); border-color: rgba(255,255,255,0.08); }
        .crm-kanban-card:hover { box-shadow: 0 4px 10px rgba(0,0,0,0.08); }
        .crm-kanban-card-link { display: block; text-decoration: none; color: inherit; }
        .crm-kanban-card-id { font-size: 0.7rem; font-weight: 600; color: #6b7280; }
        .crm-kanban-card-title { font-size: 0.875rem; font-weight: 500; margin-top: 0.125rem; }
        .crm-kanban-card-amount { font-size: 0.7rem; color: #6b7280; margin-top: 0.25rem; }
        .crm-kanban-card-meta { font-size: 0.625rem; color: #9ca3af; margin-top: 0.125rem; }
        .crm-kanban-actions { position: absolute; top: 0.375rem; right: 0.375rem; display: flex; gap: 0.25rem; opacity: 0; transition: opacity 120ms ease; }
        .crm-kanban-card:hover .crm-kanban-actions { opacity: 1; }
        .crm-kanban-btn { font-size: 0.65rem; padding: 0.15rem 0.4rem; border-radius: 0.25rem; border: 0; cursor: pointer; color: #fff; }
        .crm-kanban-btn-success { background: #10b981; } .crm-kanban-btn-success:hover { background: #059669; }
        .crm-kanban-btn-danger  { background: #ef4444; } .crm-kanban-btn-danger:hover  { background: #dc2626; }
        .crm-kanban-empty { color: #6b7280; font-size: 0.875rem; text-align: center; padding: 3rem 1rem; grid-column: 1 / -1; }
    </style>

    <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:1rem;">
        <label style="font-size:0.75rem; color:#6b7280;">Owner</label>
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
                        group: 'crm-quotes', animation: 150, ghostClass: 'opacity-50',
                        onEnd: (evt) => {
                            const stageId = evt.to.getAttribute('data-stage-id');
                            const quoteId = evt.item.getAttribute('data-quote-id');
                            if (stageId && quoteId) { $wire.moveQuote(quoteId, parseInt(stageId)); }
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
            } else { initSortables(); }
        "
    >
        <div x-ref="board" class="crm-kanban"
             style="grid-template-columns: repeat({{ max($stages->count(), 1) }}, minmax(260px, 1fr));">
            @forelse ($stages as $stage)
                @php $stageQuotes = $quotesByStage[$stage->id] ?? collect(); @endphp
                <div class="crm-kanban-col">
                    <div class="crm-kanban-col-head">
                        <div class="crm-kanban-col-title">{{ $stage->name }}</div>
                        <div class="crm-kanban-col-meta">
                            <div>{{ $stageQuotes->count() }}</div>
                            <div class="crm-kanban-col-total">
                                {{ \VentureDrake\LaravelCrmFilament\Support\MoneyForm::display($this->getStageTotal($stage->id, $quotesByStage), $currency) }}
                            </div>
                        </div>
                    </div>
                    <div data-kanban-column data-stage-id="{{ $stage->id }}" class="crm-kanban-list">
                        @foreach ($stageQuotes as $quote)
                            <div data-quote-id="{{ $quote->external_id }}" class="crm-kanban-card">
                                <div class="crm-kanban-actions">
                                    <button type="button" wire:click.stop="markAccepted('{{ $quote->external_id }}')"
                                            class="crm-kanban-btn crm-kanban-btn-success" title="Mark accepted">Accept</button>
                                    <button type="button" wire:click.stop="markRejected('{{ $quote->external_id }}')"
                                            class="crm-kanban-btn crm-kanban-btn-danger" title="Mark rejected">Reject</button>
                                </div>
                                <a href="{{ route('filament.crm.resources.quotes.edit', ['record' => $quote->external_id]) }}"
                                   class="crm-kanban-card-link">
                                    <div class="crm-kanban-card-id">{{ $quote->quote_id }}</div>
                                    <div class="crm-kanban-card-title">{{ $quote->title }}</div>
                                    @if ($quote->total)
                                        <div class="crm-kanban-card-amount">{{ \VentureDrake\LaravelCrmFilament\Support\MoneyForm::display($quote->total, $quote->currency) }}</div>
                                    @endif
                                    @if ($quote->expire_at)
                                        <div class="crm-kanban-card-meta">Expires {{ \Carbon\Carbon::parse($quote->expire_at)->format('M j') }}</div>
                                    @endif
                                </a>
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="crm-kanban-empty">No pipeline stages configured for Quotes.</div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
