@php
    // Single-item mode: caller passed $activity + $last so this partial
    // renders exactly one timeline row. Used by the ActivityFeed page
    // which paginates activities via $this->getActivities().
    $singleItemMode = isset($activity);
@endphp

@if ($singleItemMode)
    @php
        $userName = $activity->causeable->name ?? null;
        $entityType = $activity->recordable_type
            ? strtolower(class_basename($activity->recordable_type))
            : null;
        $verb = $activity->event ?: 'created';
        $title = ($userName ? $userName . ' ' . $verb . ' a ' : ucfirst($verb) . ' a ')
            . ($entityType ?? 'activity');
        $isLast = (bool) ($last ?? false);
        $recordable = $activity->recordable;
    @endphp

    <div class="crm-timeline-item @if ($isLast) crm-timeline-item--last @endif" data-testid="crm-card-area-activity-item">
        <div class="crm-timeline-rail">
            <span class="crm-timeline-bullet" aria-hidden="true"></span>
            @if (! $isLast)
                <span class="crm-timeline-connector" aria-hidden="true"></span>
            @endif
        </div>
        <div class="crm-timeline-body">
            <div class="crm-timeline-title">{{ $title }}</div>
            <div class="crm-timeline-subtitle">{{ $activity->created_at?->format('m/d/Y h:i A') }}</div>
            @if ($related ?? false)
                <div class="crm-card-badges">
                    @include('laravel-crm-filament::partials.crm-related-badge', ['related' => true])
                </div>
            @endif
            @if ($recordable)
                <div class="crm-timeline-recordable">
                    @switch($entityType)
                        @case('note')
                            @include('laravel-crm-filament::partials.crm-card-note', ['record' => $recordable])
                            @break
                        @case('task')
                            @include('laravel-crm-filament::partials.crm-card-task', ['record' => $recordable])
                            @break
                        @case('call')
                            @include('laravel-crm-filament::partials.crm-card-call', ['record' => $recordable])
                            @break
                        @case('meeting')
                            @include('laravel-crm-filament::partials.crm-card-meeting', ['record' => $recordable])
                            @break
                        @case('lunch')
                            @include('laravel-crm-filament::partials.crm-card-lunch', ['record' => $recordable])
                            @break
                        @case('file')
                            @include('laravel-crm-filament::partials.crm-card-file', ['record' => $recordable])
                            @break
                    @endswitch
                </div>
            @endif
        </div>
    </div>
@else
    <div class="crm-card-area-activity" data-testid="crm-card-area-activity">
        @include('laravel-crm-filament::partials.crm-card-styles')

        @php
            $activityRows = $this->relatedActivityRows();
        @endphp

        @forelse ($activityRows as $i => $activity)
            @include('laravel-crm-filament::crm-activity', ['activity' => $activity, 'last' => $loop->last, 'related' => $this->isRelatedActivityRecord($activity)])
        @empty
            <div class="crm-card-empty">No activity yet.</div>
        @endforelse
    </div>
@endif
