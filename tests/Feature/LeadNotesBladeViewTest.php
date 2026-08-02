<?php

declare(strict_types=1);

beforeEach(function () {
    $this->bladePath = __DIR__ . '/../../resources/views/crm-notes.blade.php';
    $this->partialPath = __DIR__ . '/../../resources/views/partials/crm-card-styles.blade.php';
});

it('renders the lead-notes blade file at the expected path', function () {
    expect(file_exists($this->bladePath))->toBeTrue();
});

it('includes the shared lead-card-styles partial in place of an inline style block', function () {
    $blade = file_get_contents($this->bladePath);

    expect($blade)->toContain("@include('laravel-crm-filament::partials.crm-card-styles')");

    // Inline @once <style> block must no longer live on this view.
    expect($blade)->not->toContain('@once');
    expect($blade)->not->toContain('<style>');
    expect($blade)->not->toContain('@endonce');
});

it('the shared lead-card-styles partial declares CSS custom properties for dark mode', function () {
    expect(file_exists($this->partialPath))->toBeTrue();

    $partial = file_get_contents($this->partialPath);

    expect($partial)->toContain('@once');
    expect($partial)->toContain('<style>');
    expect($partial)->toContain('@endonce');

    expect($partial)->toContain('--crm-card-bg:');
    expect($partial)->toContain('--crm-card-text:');
    expect($partial)->toContain('--crm-card-pill-bg:');
    expect($partial)->toContain('html.dark .crm-card-area-notes');

    // Old --crm-note-* property names are gone from the partial.
    expect($partial)->not->toContain('--crm-note-');
});

it('exposes the add-note form wired to createNote with content + noted_at bindings', function () {
    $blade = file_get_contents($this->bladePath);

    expect($blade)->toContain('@if ($editingId === null)');
    expect($blade)->toContain('wire:submit="createNote"');
    expect($blade)->toContain('wire:model="data.content"');
    expect($blade)->toContain('wire:model="data.noted_at"');
    expect($blade)->toContain("__('laravel-crm-filament::labels.actions.save')");
});

it('loops the rolled-up note rows sorted by created_at desc', function () {
    $blade = file_get_contents($this->bladePath);

    // US-009: the raw `getOwnerRecord()->notes()->orderBy(...)` lookup moved
    // into RollsUpRelatedActivity::relatedActivityRows(), which defaults to
    // created_at desc and honours `show_related_activity`.
    expect($blade)->toContain('$this->relatedActivityRows()');
    expect($blade)->toContain('@forelse');
});

it('renders the relative-time + creator-name header', function () {
    $blade = file_get_contents($this->bladePath);

    expect($blade)->toContain('$note->created_at?->diffForHumans()');
    expect($blade)->toContain('$note->createdByUser?->name');
});

it('renders the note body with escaped output, never {!! !!}', function () {
    $blade = file_get_contents($this->bladePath);

    expect($blade)->toContain('{{ $note->content }}');
    expect($blade)->not->toContain('{!! $note->content !!}');
    expect($blade)->not->toContain('{!!');
});

it('renders the Noted-at footer pill in the prescribed format', function () {
    $blade = file_get_contents($this->bladePath);

    expect($blade)->toContain('@if ($note->noted_at)');
    expect($blade)->toContain('Noted at');
    expect($blade)->toContain("\$note->noted_at->format('h:i A')");
    expect($blade)->toContain("\$note->noted_at->format('M d, Y')");
    expect($blade)->toContain('crm-card-pill');
});

it('exposes a three-dot dropdown with Edit and Delete wired to editNote / deleteNote', function () {
    $blade = file_get_contents($this->bladePath);

    expect($blade)->toContain('crm-card-dropdown');
    expect($blade)->toContain('x-data="{ open: false }"');
    expect($blade)->toContain('wire:click="editNote({{ $note->id }})"');
    expect($blade)->toContain('wire:click="deleteNote({{ $note->id }})"');
    expect($blade)->toContain('>Edit<');
    expect($blade)->toContain('>Delete<');
});

it('swaps the card body for the inline edit form when $editingId === $note->id', function () {
    $blade = file_get_contents($this->bladePath);

    expect($blade)->toContain('@if ($editingId === $note->id)');
    expect($blade)->toContain('wire:submit="updateNote"');
    expect($blade)->toContain('wire:click="cancelEdit"');
});

it('binds the edit form inputs to the same data.content / data.noted_at state', function () {
    $blade = file_get_contents($this->bladePath);

    // Two occurrences of each binding (one for create, one for edit).
    expect(substr_count($blade, 'wire:model="data.content"'))->toBeGreaterThanOrEqual(2);
    expect(substr_count($blade, 'wire:model="data.noted_at"'))->toBeGreaterThanOrEqual(2);
});

it('renders an empty-state message when the lead has no notes', function () {
    $blade = file_get_contents($this->bladePath);

    expect($blade)->toContain('@empty');
    expect($blade)->toContain('No notes yet');
});

it('the lead-notes blade no longer carries any crm-note-* class references', function () {
    $blade = file_get_contents($this->bladePath);

    // The rename is exhaustive: no class attribute on this view should still
    // reference the old crm-note-* prefix. (data-testid="crm-lead-note-*"
    // and id="crm-lead-note-*" use a "crm-lead-note-" prefix and are NOT
    // class references — those stay.)
    expect($blade)->not->toContain('class="crm-note-');
    expect($blade)->not->toContain(' crm-note-');
});
