<?php

namespace App\Livewire\Invoices;

use App\Livewire\Component;
use App\Models\Invoice;
use App\Services\BillmanagerMigration\AccountAccess;
use Illuminate\Support\Facades\Auth;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public function render()
    {
        return view('invoices.index', [
            'invoices' => Invoice::whereIn('user_id', AccountAccess::visibleOwnerIds(Auth::user()))->with(['user', 'snapshot', 'items'])->orderBy('id', 'desc')->paginate(config('settings.pagination')),
        ])->layoutData([
            'title' => __('invoices.invoices'),
            'sidebar' => true,
        ]);
    }
}
