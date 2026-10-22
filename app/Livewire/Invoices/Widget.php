<?php

namespace App\Livewire\Invoices;

use App\Livewire\Component;
use App\Models\Invoice;
use App\Services\BillmanagerMigration\AccountAccess;
use Illuminate\Support\Facades\Auth;
use Livewire\WithPagination;

class Widget extends Component
{
    use WithPagination;

    public function render()
    {
        return view('invoices.widget', [
            'invoices' => Invoice::whereIn('user_id', AccountAccess::visibleOwnerIds(Auth::user()))->orderBy('id', 'desc')->where('status', '=', 'pending')->paginate(config('settings.pagination')),
        ])->layoutData([
            'title' => __('invoices.invoices'),
        ]);
    }
}
