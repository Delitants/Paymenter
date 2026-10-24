<?php

namespace Paymenter\Extensions\Others\Bind\Livewire\Zones;

use App\Livewire\Component;
use App\Models\Extension;
use App\Services\BillmanagerMigration\AccountAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Paymenter\Extensions\Others\Bind\Models\ZoneBinding;

class Index extends Component
{
    public function render()
    {
        abort_unless(Extension::where('type', 'other')->where('extension', 'Bind')->where('enabled', true)->exists(), 404);
        $zones = ZoneBinding::with('service')->where('enabled', true)->whereNotNull('approved_at')->whereHas('service', fn ($q) => $q->whereIn('user_id', AccountAccess::visibleOwnerIds(Auth::user()))->where('status', 'active'))->orderBy('zone')->get()->filter(fn ($b) => Gate::allows('view', $b));

        return view('bind::zones.index', ['zones' => $zones])->layoutData(['title' => 'DNS', 'sidebar' => true]);
    }
}
