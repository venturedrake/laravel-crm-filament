<?php

namespace VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\Pages\Concerns;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use VentureDrake\LaravelCrm\Jobs\SendEmailCampaignRecipient;
use VentureDrake\LaravelCrm\Models\EmailCampaign;
use VentureDrake\LaravelCrm\Services\EmailCampaignService;
use VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\EmailCampaignResource;

trait HasEmailCampaignSendNowAction
{
    protected function emailCampaignSendNowAction(): Action
    {
        return Action::make('sendNow')
            ->label(__('laravel-crm-filament::labels.actions.send_now'))
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Send campaign now')
            ->modalDescription(function (EmailCampaign $record): string {
                $count = $this->resolveEmailRecipientCount($record);

                return trans_choice('laravel-crm::lang.confirm_send_now', $count, ['count' => number_format($count)]);
            })
            ->modalSubmitActionLabel('Send now')
            ->visible(fn (EmailCampaign $record) => in_array($record->status, ['draft', 'scheduled'], true))
            ->action(function (EmailCampaign $record, Action $action): void {
                // Core 2.7.0 never sends a campaign with nobody in its
                // audience; refuse rather than mark it sending with no jobs.
                $empty = $record->status === 'draft'
                    ? ! EmailCampaignResource::audienceHasRecipients($record->getAttribute('audience_type'), $record->audience_ids ?? [])
                    : ! $record->recipients()->where('status', 'pending')->exists();

                if ($empty) {
                    EmailCampaignResource::notifyEmptyAudience();

                    $action->cancel();
                }

                if ($record->status === 'draft') {
                    app(EmailCampaignService::class)->materialiseRecipients($record);
                }

                $record->update([
                    'status' => 'sending',
                    'scheduled_at' => $record->scheduled_at ?? now(),
                ]);

                $count = 0;
                $record->recipients()->where('status', 'pending')->get()->each(function ($recipient) use (&$count) {
                    SendEmailCampaignRecipient::dispatch($recipient);
                    $count++;
                });

                Notification::make()
                    ->title('Campaign send dispatched')
                    ->body($count . ' recipient job(s) queued')
                    ->success()
                    ->send();
            });
    }

    /**
     * A draft has no recipient rows yet, so count its audience the way
     * materialiseRecipients() will (core 2.7.0's EmailCampaignAudience: one
     * per unique subscribed address). A scheduled campaign already has them.
     */
    private function resolveEmailRecipientCount(EmailCampaign $record): int
    {
        if ($record->status === 'draft') {
            return EmailCampaignResource::audienceCount($record->getAttribute('audience_type'), $record->audience_ids ?? []);
        }

        return (int) $record->recipients()->where('status', 'pending')->count();
    }
}
