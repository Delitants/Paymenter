<?php

namespace App\Livewire\Services;

use App\Livewire\Component;
use App\Models\Service;
use App\Services\BillmanagerMigration\AccountAccess;
use Illuminate\Support\Facades\Auth;
use Livewire\WithPagination;

class Widget extends Component
{
    use WithPagination;

    public $status = null;

    public function render()
    {
        $query = Service::whereIn('user_id', AccountAccess::visibleOwnerIds(Auth::user()));

        if ($this->status) {
            $query->where('status', $this->status);
        } else {
            $query->where('status', '!=', 'cancelled');
        }

        return view('services.widget', [
            'services' => $query->paginate(config('settings.pagination')),
        ])->layoutData([
            'title' => 'Services',
        ]);
    }
}
