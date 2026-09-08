<?php

namespace VentureDrake\LaravelCrmFilament\Resources\Invoices\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use VentureDrake\LaravelCrm\Models\Invoice;
use VentureDrake\LaravelCrmFilament\Concerns\HasCrmSideBySideRelationManagers;
use VentureDrake\LaravelCrmFilament\Resources\Invoices\InvoiceResource;

class ViewInvoice extends ViewRecord
{
    use Concerns\HasInvoiceMarkPaidAction;
    use Concerns\HasInvoicePortalAction;
    use Concerns\HasInvoiceSendAction;
    use HasCrmSideBySideRelationManagers;

    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InvoiceResource::backToIndexAction(),
            $this->invoiceSendAction()
                ->button()
                ->label(__('laravel-crm-filament::labels.actions.send'))
                ->color('gray'),
            $this->invoiceMarkPaidAction()
                ->button()
                ->label(__('laravel-crm-filament::labels.actions.pay'))
                ->color('gray'),
            $this->invoicePortalAction()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color('gray'),
            $this->invoiceDownloadPdfAction()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-arrow-down-tray'),
            InvoiceResource::pdfPreviewAction('invoice')
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-document-magnifying-glass'),
            InvoiceResource::portalLinkAction('laravel-crm.portal.invoices.show')
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-link'),
            Actions\EditAction::make()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-pencil-square'),
            Actions\DeleteAction::make()
                ->button()
                ->hiddenLabel()
                ->icon('heroicon-m-trash'),
        ];
    }

    public function getHeading(): string | Htmlable
    {
        /** @var Invoice|null $record */
        $record = $this->record;

        if ($record === null) {
            return parent::getHeading();
        }

        $badges = '';

        if ((int) $record->sent === 1) {
            $badges .= ' <span class="ml-2 inline-flex items-center rounded-full bg-emerald-500 px-3 py-1 text-sm font-medium text-white align-middle">Sent</span>';
        }

        if ($record->fully_paid_at === null && $record->due_date !== null) {
            $due = Carbon::parse($record->due_date);

            if ($due->isPast()) {
                $badges .= ' <span class="ml-2 inline-flex items-center rounded-full bg-rose-500 px-3 py-1 text-sm font-medium text-white align-middle">' . e($due->diffForHumans(null, true)) . ' ago Overdue</span>';
            }
        }

        return new HtmlString(e((string) $record->title) . $badges);
    }
}
