<x-filament-panels::page>
    <style>
        .crm-calendar-toolbar {
            display: flex; flex-wrap: wrap; align-items: center; gap: 1rem;
            margin-bottom: 1rem;
        }
        .crm-calendar-toolbar label {
            font-size: 0.75rem; color: #6b7280;
        }
        .crm-calendar-toolbar select {
            font-size: 0.875rem; padding: 0.375rem 0.5rem;
            border-radius: 0.375rem; border: 1px solid #d1d5db; background: #fff;
        }
        html.dark .crm-calendar-toolbar select {
            background: rgb(31, 41, 55); border-color: rgba(255, 255, 255, 0.12); color: #fff;
        }
        .crm-calendar-types {
            display: flex; align-items: center; gap: 0.5rem;
            font-size: 0.75rem;
        }
        .crm-calendar-types label {
            display: inline-flex; align-items: center; gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem; background: rgba(0, 0, 0, 0.04);
            cursor: pointer;
        }
        html.dark .crm-calendar-types label { background: rgba(255, 255, 255, 0.06); }
        .crm-calendar-host {
            background: #fff; border-radius: 0.75rem; padding: 0.75rem;
            border: 1px solid rgba(0, 0, 0, 0.06);
        }
        html.dark .crm-calendar-host {
            background: rgb(31, 41, 55); border-color: rgba(255, 255, 255, 0.08);
        }
        .fc .fc-toolbar-title { font-size: 1.1rem; }
        .fc .fc-button { font-size: 0.8rem; }
    </style>

    <div class="crm-calendar-toolbar">
        <div style="display:flex; align-items:center; gap:0.5rem;">
            <label>Owner</label>
            @if ($this->ownerSearchEnabled())
                <input type="search" wire:model.live.debounce.300ms="ownerSearch" placeholder="Search owners…" autocomplete="off">
            @endif
            <select wire:model.live="ownerFilter">
                <option value="">Everyone</option>
                @foreach ($this->getOwners() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div class="crm-calendar-types">
            <span style="color:#6b7280;">Show</span>
            <label><input type="checkbox" wire:model.live="typeFilters.task"> Tasks</label>
            <label><input type="checkbox" wire:model.live="typeFilters.call"> Calls</label>
            <label><input type="checkbox" wire:model.live="typeFilters.meeting"> Meetings</label>
            <label><input type="checkbox" wire:model.live="typeFilters.lunch"> Lunches</label>
        </div>
    </div>

    <div
        wire:ignore
        x-data="{
            calendar: null,
            mountFullCalendar() {
                const host = this.$refs.host;
                if (!host) return;
                this.calendar = new window.FullCalendar.Calendar(host, {
                    initialView: 'dayGridMonth',
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek',
                    },
                    editable: true,
                    events: (info, success, failure) => {
                        $wire.getEventsForRange(info.startStr, info.endStr).then(success).catch(failure);
                    },
                    eventDrop: (info) => {
                        const props = info.event.extendedProps || {};
                        const externalId = props.externalId;
                        const type = props.type;
                        if (!externalId || !type) return;
                        $wire.moveEvent(externalId, type, info.event.start.toISOString());
                    },
                });
                this.calendar.render();
                Livewire.on('calendar-refetch', () => this.calendar?.refetchEvents());
            },
            loadFullCalendar() {
                if (typeof window.FullCalendar !== 'undefined') {
                    this.mountFullCalendar();
                    return;
                }
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css';
                document.head.appendChild(link);
                const script = document.createElement('script');
                script.src = 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js';
                script.onload = () => this.mountFullCalendar();
                document.head.appendChild(script);
            },
        }"
        x-init="loadFullCalendar()"
    >
        <div class="crm-calendar-host" x-ref="host"></div>
    </div>
</x-filament-panels::page>
