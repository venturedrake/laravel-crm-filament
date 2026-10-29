<?php

use Illuminate\Support\Str;
use VentureDrake\LaravelCrm\Models\ChatConversation;
use VentureDrake\LaravelCrm\Models\ChatVisitor;
use VentureDrake\LaravelCrm\Models\ChatWidget;
use VentureDrake\LaravelCrm\Models\Lead;
use VentureDrake\LaravelCrm\Observers\ChatConversationObserver;
use VentureDrake\LaravelCrm\Observers\ChatVisitorObserver;
use VentureDrake\LaravelCrm\Observers\ChatWidgetObserver;
use VentureDrake\LaravelCrmFilament\Resources\Chat\Pages\ListChatConversations;
use VentureDrake\LaravelCrmFilament\Resources\Chat\Pages\ViewChatConversation;

use function Pest\Livewire\livewire;

/**
 * Core 2.6.0: a conversation whose lead was since deleted is no longer
 * "already converted". The panel hid Convert to lead on lead_id alone, so such
 * a conversation could never be converted again.
 */
beforeEach(function () {
    actingAsCrmAdmin();

    ChatWidget::observe(ChatWidgetObserver::class);
    ChatVisitor::observe(ChatVisitorObserver::class);
    ChatConversation::observe(ChatConversationObserver::class);

    $widget = ChatWidget::create(['public_key' => 'pk-' . uniqid(), 'name' => 'Test widget']);
    $visitor = ChatVisitor::create([
        'chat_widget_id' => $widget->id,
        'visitor_token' => Str::random(40),
        'name' => 'Alice Visitor',
    ]);

    $this->lead = Lead::create(['external_id' => (string) Str::uuid(), 'title' => 'Chat with Alice', 'lead_status_id' => 1]);

    $this->conversation = ChatConversation::create([
        'chat_widget_id' => $widget->id,
        'chat_visitor_id' => $visitor->id,
        'status' => 'open',
        'lead_id' => $this->lead->id,
    ]);
});

it('hides Convert to lead while the lead exists', function () {
    livewire(ViewChatConversation::class, ['record' => $this->conversation->external_id])
        ->assertActionHidden('convertToLead');

    livewire(ListChatConversations::class)
        ->assertTableActionHidden('convertToLead', $this->conversation);
});

it('offers Convert to lead again once the lead is deleted', function () {
    $this->lead->delete();

    livewire(ViewChatConversation::class, ['record' => $this->conversation->external_id])
        ->assertActionVisible('convertToLead')
        ->callAction('convertToLead');

    $conversation = $this->conversation->fresh();

    expect($conversation->lead_id)->not->toBe($this->lead->id)
        ->and($conversation->lead)->not->toBeNull();
});
