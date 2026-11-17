<?php

namespace VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\Pages;

use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;
use VentureDrake\LaravelCrm\Mail\EmailCampaignMessage;
use VentureDrake\LaravelCrm\Models\EmailCampaign;
use VentureDrake\LaravelCrm\Services\EmailCampaignService;
use VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\EmailCampaignResource;
use VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\Pages\Concerns\HasEmailCampaignSendNowAction;
use VentureDrake\LaravelCrmFilament\Widgets\EmailCampaignSendsOverTimeChart;
use VentureDrake\LaravelCrmFilament\Widgets\EmailCampaignStatsWidget;
use VentureDrake\LaravelCrmFilament\Widgets\EmailCampaignTopUrlsWidget;

class ViewEmailCampaign extends ViewRecord
{
    use HasEmailCampaignSendNowAction;

    protected static string $resource = EmailCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EmailCampaignResource::backToIndexAction(),
            Actions\Action::make('preview')
                ->label(__('laravel-crm-filament::labels.actions.preview'))
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->modalHeading(fn (EmailCampaign $record) => 'Preview: ' . $record->name)
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn (EmailCampaign $record) => new HtmlString(static::previewSubjectHtml($record) . EmailCampaignMessage::renderPreview($record->body ?? '', $record->preview_text ?? '', $record->team_id))),
            $this->emailCampaignSendNowAction(),
            Actions\Action::make('schedule')
                ->label(__('laravel-crm-filament::labels.actions.schedule'))
                ->icon('heroicon-o-calendar')
                ->color('primary')
                ->visible(fn (EmailCampaign $record) => $record->isEditable())
                ->schema([
                    EmailCampaignResource::scheduledAtPicker('scheduled_at')
                        ->label(__('laravel-crm-filament::labels.campaign.send_at'))
                        ->required(),
                ])
                ->action(function (array $data, EmailCampaign $record, Actions\Action $action): void {
                    // Same guard as the create/edit save: core 2.7.0 never
                    // schedules a campaign with nobody to send to.
                    if (! EmailCampaignResource::audienceHasRecipients($record->getAttribute('audience_type'), $record->audience_ids ?? [])) {
                        EmailCampaignResource::notifyEmptyAudience();

                        $action->cancel();
                    }

                    app(EmailCampaignService::class)->schedule($record, $data['scheduled_at']);
                    Notification::make()->title('Campaign scheduled')->success()->send();
                }),
            Actions\Action::make('cancel')
                ->label(__('laravel-crm-filament::labels.actions.cancel'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (EmailCampaign $record) => $record->isCancellable())
                ->action(function (EmailCampaign $record): void {
                    app(EmailCampaignService::class)->cancel($record);
                    Notification::make()->title('Campaign cancelled')->success()->send();
                }),
            Actions\EditAction::make()
                ->visible(fn (EmailCampaign $record) => $record->isEditable())
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-pencil-square'),
            Actions\DeleteAction::make()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-trash')
                ->requiresConfirmation(),
        ];
    }

    /**
     * The subject as the preview recipient sees it, placeholders filled in
     * (core 2.7.0's EmailCampaignMessage::renderPreviewSubject()).
     */
    protected static function previewSubjectHtml(EmailCampaign $record): string
    {
        $subject = EmailCampaignMessage::renderPreviewSubject($record->subject ?? '');

        return '<div style="margin-bottom:0.75rem;font-size:0.875rem;">'
            . '<span style="color:#6b7280;">' . e(ucfirst(__('laravel-crm::lang.subject'))) . ':</span> '
            . '<strong>' . e($subject) . '</strong>'
            . '</div>';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            EmailCampaignStatsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int | array
    {
        return 4;
    }

    protected function getFooterWidgets(): array
    {
        return [
            EmailCampaignSendsOverTimeChart::class,
            EmailCampaignTopUrlsWidget::class,
        ];
    }
}
