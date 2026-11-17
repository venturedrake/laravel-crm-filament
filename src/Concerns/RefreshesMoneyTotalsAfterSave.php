<?php

namespace VentureDrake\LaravelCrmFilament\Concerns;

/**
 * Put the stored header totals back into the form's summary after a save.
 *
 * Filament's EditRecord::save() does not refill the form. Core's services
 * recompute the header from the saved lines and store
 * `total = subtotal - discount + tax + adjustments`, while the live line-item
 * recalc leaves discount and adjustment out. Without this the summary keeps
 * what the form showed before the save until the page is reloaded.
 *
 * refreshFormData() fills only these paths, through the page's own
 * mutateFormDataBeforeFill(), so the cents are converted as they are on load.
 * It needs handleRecordUpdate() to return the refreshed record.
 */
trait RefreshesMoneyTotalsAfterSave
{
    protected function afterSave(): void
    {
        $this->refreshFormData($this->moneyTotalsStatePaths());
    }

    /**
     * The summary fields on the form. Override on a form built with
     * MoneyTotalsRow::make(withAdjustments: false).
     *
     * @return list<string>
     */
    protected function moneyTotalsStatePaths(): array
    {
        return ['sub_total', 'discount', 'tax', 'adjustment', 'total'];
    }
}
