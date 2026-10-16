<?php

namespace Tests\Feature;

use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Fixtures\ExtensionConfigForm;
use Tests\TestCase;

class ExtensionConfigFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_cloud_image_and_iso_choices_follow_current_form_state(): void
    {
        $form = Livewire::test(ExtensionConfigForm::class, ['fields' => [
            ['name' => 'settings.iso_image', 'type' => 'select', 'live' => true, 'options' => ['disabled' => 'None', 'test.iso' => 'ISO']],
            ['name' => 'settings.cloud_image', 'type' => 'select', 'live' => true, 'options' => ['disabled' => 'None', 'test.qcow2' => 'Cloud'], 'disabled_if' => ['iso_image' => ['not_in', 'disabled', '']]],
        ], 'data' => ['settings' => ['iso_image' => 'disabled', 'cloud_image' => 'disabled']]]);
        $this->assertFalse($form->instance()->form->getComponents()[1]->isDisabled());
        $form->set('data.settings.iso_image', 'test.iso');
        $this->assertTrue($form->instance()->form->getComponents()[1]->isDisabled());
        $form->set('data.settings.iso_image', 'disabled');
        $this->assertFalse($form->instance()->form->getComponents()[1]->isDisabled());
    }

    public function test_numeric_settings_obey_their_disable_condition(): void
    {
        $form = Livewire::test(ExtensionConfigForm::class, ['fields' => [
            ['name' => 'mode', 'type' => 'select', 'live' => true, 'options' => ['auto' => 'Auto', 'specific' => 'Specific']],
            ['name' => 'count', 'type' => 'number', 'disabled_if' => ['mode' => ['!=', 'specific']]],
        ], 'data' => ['mode' => 'auto', 'count' => 1]]);
        $this->assertTrue($form->instance()->form->getComponents()[1]->isDisabled());
        $form->set('data.mode', 'specific');
        $this->assertFalse($form->instance()->form->getComponents()[1]->isDisabled());
    }

    public function test_specific_ip_choices_use_allocator_row_ids_and_refresh_availability(): void
    {
        $pool = IpPool::create(['name' => 'Documentation pool', 'network_address' => '192.0.2.0/24', 'ip_version' => 'ipv4']);
        $available = IpAddress::create(['ip_pool_id' => $pool->id, 'ip_address' => '192.0.2.10', 'is_assigned' => false]);
        $assigned = IpAddress::create(['ip_pool_id' => $pool->id, 'ip_address' => '192.0.2.11', 'is_assigned' => true]);
        $owned = IpAddress::create(['ip_pool_id' => $pool->id, 'ip_address' => '192.0.2.12', 'is_assigned' => false, 'assigned_to_id' => 42]);
        $typed = IpAddress::create(['ip_pool_id' => $pool->id, 'ip_address' => '192.0.2.13', 'is_assigned' => false, 'assigned_to_type' => Service::class]);
        $otherPool = IpPool::create(['name' => 'Other pool', 'network_address' => '198.51.100.0/24', 'ip_version' => 'ipv4']);
        $foreign = IpAddress::create(['ip_pool_id' => $otherPool->id, 'ip_address' => '198.51.100.10', 'is_assigned' => false]);
        $form = Livewire::test(ExtensionConfigForm::class, ['fields' => [
            ['name' => 'settings.ipv4_pool_id', 'type' => 'select', 'live' => true, 'options' => [$pool->id => 'Pool']],
            ['name' => 'settings.ipv4_address', 'type' => 'select', 'loadIpAddresses' => true, 'searchable' => true],
        ], 'data' => ['settings' => ['ipv4_pool_id' => $pool->id, 'ipv4_address' => $available->id]]]);
        $select = $form->instance()->form->getComponents()[1];
        $this->assertSame('192.0.2.10', $select->getOptions()[$available->id] ?? null);
        foreach ([$assigned, $owned, $typed, $foreign] as $excluded) {
            $this->assertArrayNotHasKey($excluded->id, $select->getOptions());
            $this->assertArrayNotHasKey($excluded->id, $select->getSearchResults($excluded->ip_address));
        }
        $this->assertSame('192.0.2.10', $select->getOptionLabel());
        $this->assertSame('192.0.2.10', $select->getSearchResults('192.0.2.10')[$available->id] ?? null);
        $form->set('data.settings.ipv4_address', $foreign->id);
        $this->assertNotSame($foreign->ip_address, $form->instance()->form->getComponents()[1]->getOptionLabel());
        $available->update(['is_assigned' => true]);
        $this->assertArrayNotHasKey($available->id, $select->getOptions());
    }
}
