<?php

namespace App\Livewire\Billing;

use App\Livewire\Component;
use App\Models\BillmanagerFinancialRecord;
use App\Services\BillmanagerMigration\AccountAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        $query = BillmanagerFinancialRecord::whereIn('user_id', $owners)
            ->where('status', '!=', 'preliminary');
        $record = null;
        if ($this->recordId !== null) {
            $record = (clone $query)->findOrFail($this->recordId);
            Gate::authorize('view', $record);
        }
        // Find the latest account snapshot and each record's latest version before filtering by status.
        $versions = DB::table('billmanager_financial_records as archive_record')
            ->join('billmanager_imports as archive_import', 'archive_import.id', '=', 'archive_record.import_id')
            ->whereIn('archive_record.user_id', $owners)
            ->select('archive_record.id', 'archive_record.import_id', 'archive_record.source_table')
            ->selectRaw('MAX(archive_record.import_id) OVER (
                PARTITION BY archive_import.source_host, archive_record.user_id, archive_record.source_account_id
            ) AS latest_account_import')
            ->selectRaw('MAX(archive_record.import_id) OVER (
                PARTITION BY archive_import.source_host, archive_record.user_id, archive_record.source_account_id,
                    archive_record.source_table, archive_record.source_id
            ) AS latest_record_import');
        $latest = DB::query()->fromSub($versions, 'archive_versions')->select('id')
            ->whereRaw('import_id = CASE WHEN source_table = ? THEN latest_account_import ELSE latest_record_import END', ['subaccounts']);

        $query->whereIn('id', $latest);
        if (in_array($this->kind, ['payments', 'invoices', 'subaccounts'], true)) {
            $query->where('source_table', $this->kind);
        }

        return view('billing.legacy-history', ['records' => $query->orderByDesc('id')->paginate(20), 'record' => $record])->layoutData([
            'title' => __('Billing history'), 'sidebar' => true,
        ]);
    }
}
