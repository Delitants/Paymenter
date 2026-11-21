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
        $query = BillmanagerFinancialRecord::whereIn('billmanager_financial_records.user_id', $owners)
            ->where('billmanager_financial_records.status', '!=', 'preliminary');
        $record = null;
        if ($this->recordId !== null) {
            $record = (clone $query)->findOrFail($this->recordId);
            Gate::authorize('view', $record);
        }
        // Keep the newest version of each historical record and the latest account balance snapshot.
        $query->select('billmanager_financial_records.*')
            ->join('billmanager_imports as archive_import', 'archive_import.id', '=', 'billmanager_financial_records.import_id')
            ->whereNotExists(function ($newer) {
                $newer->selectRaw('1')
                    ->from('billmanager_financial_records as newer_record')
                    ->join('billmanager_imports as newer_import', 'newer_import.id', '=', 'newer_record.import_id')
                    ->whereColumn('newer_import.source_host', 'archive_import.source_host')
                    ->whereColumn('newer_record.user_id', 'billmanager_financial_records.user_id')
                    ->whereColumn('newer_record.source_account_id', 'billmanager_financial_records.source_account_id')
                    ->whereColumn('newer_record.import_id', '>', 'billmanager_financial_records.import_id')
                    ->where(function ($identity) {
                        $identity->where('billmanager_financial_records.source_table', 'subaccounts')
                            ->orWhere(function ($record) {
                                $record->whereColumn('newer_record.source_table', 'billmanager_financial_records.source_table')
                                    ->whereColumn('newer_record.source_id', 'billmanager_financial_records.source_id');
                            });
                    });
            });
        if (in_array($this->kind, ['payments', 'invoices', 'subaccounts'], true)) {
            $query->where('billmanager_financial_records.source_table', $this->kind);
        }

        return view('billing.legacy-history', ['records' => $query->orderByDesc('billmanager_financial_records.id')->paginate(20), 'record' => $record])->layoutData([
            'title' => __('Billing history'), 'sidebar' => true,
        ]);
    }
}
