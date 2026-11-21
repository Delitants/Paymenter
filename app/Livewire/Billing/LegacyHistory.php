<?php

namespace App\Livewire\Billing;

use App\Livewire\Component;
use App\Models\BillmanagerFinancialRecord;
use App\Services\BillmanagerMigration\AccountAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class LegacyHistory extends Component
{
    use WithPagination;

    #[Locked]
    public ?int $recordId = null;

    public string $kind = '';

    public function mount(?BillmanagerFinancialRecord $record = null): void
    {
        if ($record?->exists) {
            Gate::authorize('view', $record);
            $this->recordId = $record->id;
        }
    }

    public function updatedKind(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $owners = AccountAccess::visibleOwnerIds(Auth::user());
        $query = BillmanagerFinancialRecord::whereIn('user_id', $owners)->where('status', '!=', 'preliminary');
        $record = null;
        if ($this->recordId !== null) {
            $record = (clone $query)->findOrFail($this->recordId);
            Gate::authorize('view', $record);
        }
        // Only the latest imported snapshot per account is shown in the list.
        $query->where('import_id', function ($latest) {
            $latest->from('billmanager_financial_records as latest_records')
                ->selectRaw('MAX(latest_records.import_id)')
                ->whereColumn('latest_records.user_id', 'billmanager_financial_records.user_id');
        });
        if (in_array($this->kind, ['payments', 'invoices', 'subaccounts'], true)) {
            $query->where('source_table', $this->kind);
        }

        return view('billing.legacy-history', ['records' => $query->orderByDesc('id')->paginate(20), 'record' => $record])->layoutData([
            'title' => __('Billing history'), 'sidebar' => true,
        ]);
    }
}
