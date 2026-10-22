<?php

namespace App\Livewire;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\Ticket;
use App\Services\BillmanagerMigration\AccountAccess;
use Illuminate\Support\Facades\Auth;

class Dashboard extends Component
{
    public $activeComponent = 'services';

    public function render()
    {
        $owners = AccountAccess::visibleOwnerIds(Auth::user());

        return view('dashboard', [
            'activeServiceCount' => Service::whereIn('user_id', $owners)->where('status', 'active')->count(),
            'openTicketCount' => Ticket::whereIn('user_id', $owners)->where('status', '!=', 'closed')->count(),
            'unpaidInvoiceCount' => Invoice::whereIn('user_id', $owners)->where('status', 'pending')->count(),
        ])->layoutData([
            'sidebar' => true,
        ]);
    }
}
