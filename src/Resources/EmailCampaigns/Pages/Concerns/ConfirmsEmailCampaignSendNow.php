<?php

namespace VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\Pages\Concerns;

use Closure;
use Filament\Actions\Action;
use Filament\Support\Exceptions\Halt;
use VentureDrake\LaravelCrmFilament\Resources\EmailCampaigns\EmailCampaignResource;

/**
 * "Send now" on the create and edit pages needs a confirmed save, as in core
 * 2.7.0 (HasEmailCampaignAudience::readyToSave()).
 *
 * The Create/Save button asks first. Submitting the form another way (Enter
 * in a field) skips that button, so the save itself opens the same modal and
 * halts before anything is written.
 */
trait ConfirmsEmailCampaignSendNow
{
    /**
     * Set by the confirmed button for the rest of this request only; a
     * protected property is not part of the Livewire state the browser sends.
     */
    protected bool $sendNowConfirmed = false;

    /**
     * @param  Closure(): void  $save
     */
    protected function confirmingSendNow(Action $action, Closure $save): Action
    {
        return EmailCampaignResource::confirmingSendNow(
            $action,
            fn (): array => $this->data ?? [],
            function () use ($save): void {
                $this->sendNowConfirmed = true;

                $save();
            },
        );
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Halt
     */
    protected function ensureSendNowConfirmed(array $data, string $formActionName): void
    {
        if ($this->sendNowConfirmed || ($data['send_mode'] ?? null) !== EmailCampaignResource::SEND_NOW) {
            return;
        }

        $this->mountAction($formActionName, context: ['schemaComponent' => 'content.form-actions']);

        throw (new Halt)->rollBackDatabaseTransaction();
    }
}
