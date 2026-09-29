<?php

namespace App\Livewire\Services;

use App\Livewire\Component;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Services\BillmanagerMigration\MigrationHold;
use Livewire\Attributes\Validate;

class Cancel extends Component
{
    public Service $service;

    #[Validate('required|in:end_of_period,immediate')]
    public $type = 'end_of_period';

    #[Validate('required')]
    public $reason = '';

    public function cancelService()
    {
        $this->authorize('update', $this->service);
        MigrationHold::assertAllowed($this->service, 'cancel service');

        $this->validate();

        // Event hook will handle the cancellation (if its immediate or end of period)
        ServiceCancellation::create([
            'service_id' => $this->service->id,
            'type' => $this->type,
            'reason' => $this->reason,
        ]);

        $this->notify(__('services.cancellation_requested'), 'success', true);

        $this->redirect(route('services.show', $this->service), true);
    }

    public function render()
    {
        return view('services.cancel')->layoutData([
            'title' => __('services.cancellation', ['service' => $this->service->product->name]),
            'sidebar' => true,
        ]);
    }
}
