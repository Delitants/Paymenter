<?php

namespace App\Livewire\Services;

use App\Livewire\Component;
use App\Models\Service;
use App\Services\SavedServiceCredentials;
use Livewire\Attributes\Locked;

class Credentials extends Component
{
    public Service $service;

    #[Locked]
    public ?array $credentials = null;

    public function mount()
    {
        $this->credentials = $this->getCredentials();
    }

    public function getCredentials(): ?array
    {
        $this->authorize('update', $this->service);

        return app(SavedServiceCredentials::class)->read($this->service);
    }

    public function render()
    {
        $this->authorize('update', $this->service);

        return view('services.credentials')->layoutData([
            'title' => 'VM Credentials',
            'sidebar' => true,
        ]);
    }
}
