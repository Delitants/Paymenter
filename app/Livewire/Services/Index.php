<?php

namespace App\Livewire\Services;

use App\Livewire\Component;
use App\Models\Service;
use App\Services\BillmanagerMigration\AccountAccess;
use Illuminate\Support\Facades\Auth;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public $status = null;

    public function render()
    {
        $query = Service::whereIn('user_id', AccountAccess::visibleOwnerIds(Auth::user()))->orderBy('created_at', 'desc');

        if ($this->status) {
            $query->where('status', $this->status);
        }

        return view('services.index', [
            'services' => $query->paginate(config('settings.pagination')),
        ])->layoutData([
            'title' => 'Services',
            'sidebar' => true,
        ]);
    }
}
