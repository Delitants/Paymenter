<?php

namespace Tests\Fixtures;

use App\Classes\FilamentInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;

class ExtensionConfigForm extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    public array $data = [];

    public array $fields = [];

    public function mount(array $fields, array $data = []): void
    {
        $this->fields = $fields;
        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components(array_map(FilamentInput::convert(...), $this->fields));
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
