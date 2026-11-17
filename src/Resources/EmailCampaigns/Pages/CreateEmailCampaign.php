<?php

namespace VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use VentureDrake\LaravelCrm\Models\EmailCampaign;
use VentureDrake\LaravelCrm\Services\EmailCampaignService;
use VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\EmailCampaignResource;
use VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\Pages\Concerns\ConfirmsEmailCampaignSendNow;
use VentureDrake\LaravelCrmFilament\Support\FormPayload;

class CreateEmailCampaign extends CreateRecord
{
    use ConfirmsEmailCampaignSendNow;

    protected static string $resource = EmailCampaignResource::class;

    /**
     * "Create & create another" would skip the Send-now confirmation.
     */
    protected static bool $canCreateAnother = false;

    /**
     * Saving and scheduling are one unit: a failed schedule leaves no draft.
     */
    protected ?bool $hasDatabaseTransactions = true;

    protected function getCreateFormAction(): Action
    {
        return $this->confirmingSendNow(parent::getCreateFormAction(), fn () => $this->create());
    }

    protected function handleRecordCreation(array $data): Model
    {
        // Core 2.7.0 refuses a non-draft save with nobody in the audience
        // before anything is written.
        EmailCampaignResource::refuseEmptyAudience($data);
        $this->ensureSendNowConfirmed($data, 'create');

        /** @var EmailCampaign $campaign */
        $campaign = app(EmailCampaignService::class)->create(FormPayload::wrap($data)->toArray());

        EmailCampaignResource::scheduleAfterSave($campaign, $data);

        return $campaign->refresh();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return EmailCampaignResource::savedMessage($this->data ?? []);
    }
}
