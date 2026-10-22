<?php

namespace Tests\Feature;

use App\Livewire\Services\Credentials;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class ServiceCredentialsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_read_encrypted_guest_password_but_shared_read_only_member_cannot(): void
    {
        $product = $this->createProduct();
        $owner = User::factory()->create();
        $reader = User::factory()->create();
        $service = Service::factory()->create(['user_id' => $owner->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id]);
        $service->properties()->create(['key' => 'cloud_init_password_encrypted', 'value' => Crypt::encryptString('synthetic-test-password')]);
        $service->properties()->create(['key' => 'cloud_init_username', 'value' => 'root']);
        $account = DB::table('billmanager_accounts')->insertGetId(['source_account_id' => 91, 'owner_user_id' => $owner->id]);
        DB::table('billmanager_members')->insert(['account_id' => $account, 'user_id' => $reader->id, 'source_user_id' => 92]);
        $this->assertTrue(Gate::forUser($reader)->allows('view', $service));
        $this->assertFalse(Gate::forUser($reader)->allows('update', $service));
        Livewire::actingAs($owner)->test(Credentials::class, ['service' => $service])->assertSee('synthetic-test-password')->assertSee('root');
        Livewire::actingAs($reader)->test(Credentials::class, ['service' => $service])->assertNotFound();
    }
}
