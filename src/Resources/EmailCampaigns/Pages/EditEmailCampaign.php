<?php

namespace VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\Pages;

use Filament\Actions;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\EmailCampaign;
use VentureDrake\LaravelCrm\Services\EmailCampaignService;
use VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\EmailCampaignResource;
use VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\Pages\Concerns\ConfirmsEmailCampaignSendNow;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;

class EditEmailCampaign extends EditRecord
{
    use ConfirmsEmailCampaignSendNow;

    protected static string $resource = EmailCampaignResource::class;

    /**
     * Saving and scheduling are one unit: a failed schedule keeps the edits
     * out too.
     */
    protected ?bool $hasDatabaseTransactions = true;

    protected function getHeaderActions(): array
    {
        return [
            EmailCampaignResource::backToIndexAction(),
            Actions\ViewAction::make()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-eye'),
            Actions\DeleteAction::make()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-trash'),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        return $this->confirmingSendNow(parent::getSaveFormAction(), fn () => $this->save());
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var EmailCampaign $record */
        // Core 2.7.0 refuses a non-draft save with nobody in the audience
        // before anything is written.
        EmailCampaignResource::refuseEmptyAudience($data);
        $this->ensureSendNowConfirmed($data, 'save');

        app(EmailCampaignService::class)->update(FormPayload::wrap($data)->toArray(), $record);

        EmailCampaignResource::scheduleAfterSave($record, $data);

        return $record->refresh();
    }

    /**
     * A sent or scheduled campaign is no longer editable, so leave the form
     * for the view page, as core does.
     */
    protected function getRedirectUrl(): ?string
    {
        if (($this->data['send_mode'] ?? EmailCampaignResource::SEND_DRAFT) === EmailCampaignResource::SEND_DRAFT) {
            return parent::getRedirectUrl();
        }

        return EmailCampaignResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return EmailCampaignResource::savedMessage($this->data ?? []);
    }

    protected function getAllRelationManagers(): array
    {
        return [];
    }
}
