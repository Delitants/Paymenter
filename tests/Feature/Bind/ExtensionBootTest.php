<?php

namespace Tests\Feature\Bind;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Others\Bind\Bind;
use Paymenter\Extensions\Others\Bind\BindAccess;

final class ExtensionBootTest extends BindTestCase
{
    public function test_config_secrets_are_encrypted_and_operations_default_off(): void
    {
        $fields = (new Bind)->getConfig();
        $fields = array_column($fields, null, 'name');
        $this->assertTrue($fields['primary_private_key']['encrypted']);
        $this->assertTrue($fields['secondary_private_key']['encrypted']);
        $this->assertFalse($fields['operations_enabled']['default']);
        $row = $this->extension->settings()->create(['key' => 'primary_private_key', 'value' => 'fixture-not-a-real-key', 'encrypted' => true]);
        $raw = DB::table('settings')->where('id', $row->id)->value('value');
        $this->assertNotSame('fixture-not-a-real-key', $raw);
        $this->assertSame('fixture-not-a-real-key', Crypt::decryptString($raw));
    }

    public function test_private_keys_are_fully_redacted_from_native_audit_values(): void
    {
        foreach (['primary_private_key', 'secondary_private_key'] as $key) {
            $setting = new Setting(['key' => $key, 'value' => 'fixture-not-a-real-key', 'encrypted' => true]);
            $redactor = $setting->getAttributeModifiers()['value'];
            $this->assertSame('[REDACTED]', $redactor::redact('fixture-not-a-real-key'));
        }
    }

    public function test_disabled_extension_stops_all_access(): void
    {
        $this->extension->update(['enabled' => false]);
        $this->denied(fn () => BindAccess::assertAllowed($this->owner, $this->binding, 'inspect'));
    }
}
