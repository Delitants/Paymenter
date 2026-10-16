<?php

namespace App\Admin\Resources\InvoiceResource\Pages;

use App\Admin\Actions\AuditAction;
use App\Admin\Resources\InvoiceResource;
use App\Classes\PDF;
use App\Models\Invoice;
use App\Services\Gateways\InvoicePaymentDependencies;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    #[Locked]
    public string $paymentStatusBaseline;

    protected function afterFill(): void
    {
        $this->paymentStatusBaseline = $this->getRecord()->status;
    }

    protected function afterSave(): void
    {
        $this->paymentStatusBaseline = $this->getRecord()->status;
    }

    #[On('admin-payment-operation-completed')]
    public function refreshPaymentState(int $invoiceId): void
    {
        if ($invoiceId !== (int) $this->getRecord()->getKey()) {
            return;
        }
        $this->authorizeAccess();
        $this->getRecord()->refresh();
        $this->refreshFormData(['status']);
        $this->paymentStatusBaseline = $this->getRecord()->status;
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        $this->withPaymentStatusLock(fn () => parent::save($shouldRedirect, $shouldSendSavedNotification));
    }

    public function saveFormComponentOnly(Component $component): void
    {
        $this->withPaymentStatusLock(fn () => parent::saveFormComponentOnly($component));
    }

    /** Compare before native getState() can save repeater relationships. */
    private function withPaymentStatusLock(Closure $save): void
    {
        $this->authorizeAccess();
        DB::transaction(function () use ($save) {
            $current = (new InvoicePaymentDependencies)->lock([$this->getRecord()->getKey()])->firstWhere('id', $this->getRecord()->getKey());
            if ($current->status !== $this->paymentStatusBaseline) {
                throw ValidationException::withMessages(['data.status' => 'Invoice payment state changed. Refresh this invoice before saving.']);
            }
            $this->record = $current;
            $save();
        });
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            Action::make('pdf')
                ->label('Download PDF')
                ->action(function (Invoice $invoice) {
                    return response()->streamDownload(function () use ($invoice) {
                        echo PDF::generateInvoice($invoice)->stream();
                    }, 'invoice-' . ($invoice->number ?? $invoice->id) . '.pdf');
                }),
            AuditAction::make()
                ->auditChildren([
                    'items',
                    'transactions',
                ]),
        ];
    }
}
