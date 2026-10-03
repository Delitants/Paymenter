<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Livewire\Auth\Login;
use App\Livewire\Services\Cancel;
use App\Livewire\Services\Upgrade;
use App\Models\NotificationTemplate;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\User;
use App\Services\BillmanagerMigration\MigrationHeldException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class ReviewHoldTest extends TestCase
{
    use RefreshDatabase;

    private function hold($model): void
    {
        DB::table('billmanager_holds')->insert(['model_type' => $model::class, 'model_id' => $model->id, 'reason' => 'migration']);
    }

    private function service(): Service
    {
        $product = $this->createProduct();

        return Service::create(['user_id' => User::factory()->create()->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'price' => '10.00', 'currency_code' => 'USD', 'status' => 'active']);
    }

    private function template(): NotificationTemplate
    {
        config(['settings.mail_disable' => false]);
        $template = NotificationTemplate::updateOrCreate(['key' => 'new_login_detected'], [
            'subject' => 'Synthetic login', 'body' => 'Synthetic login',
            'enabled' => true, 'mail_enabled' => 'force', 'in_app_enabled' => 'force',
            'in_app_title' => 'Synthetic login', 'in_app_body' => 'Synthetic login', 'cc' => [], 'bcc' => [],
        ]);
        $template->forceFill(['in_app_url' => '/synthetic'])->save();

        return $template;
    }

    public function test_held_user_can_log_in_with_real_events_without_delivery(): void
    {
        $user = User::factory()->create(['password' => Hash::make('synthetic-password')]);
        $this->template();
        $this->hold($user);
        Mail::fake();
        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'synthetic-password')
            ->call('submit')->assertHasNoErrors()->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, $user->authenticationLogs()->count());
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('email_logs', 0);
        Mail::assertNothingOutgoing();
    }

    public function test_direct_delivery_is_suppressed_for_held_recipient_or_subject_only(): void
    {
        $service = $this->service();
        $user = $service->user;
        $template = $this->template();
        $this->hold($service);
        Mail::fake();
        NotificationHelper::sendEmailNotification($template, ['service' => $service], $user);
        NotificationHelper::sendInAppNotification($template, ['service' => $service], $user);
        $this->assertDatabaseCount('notifications', 0);
        Mail::assertNothingOutgoing();
        NotificationHelper::sendNotification($template->key, [], $user);
        $this->assertDatabaseCount('notifications', 1);
        Mail::assertQueued(\App\Mail\Mail::class, 1);
    }

    public function test_shared_read_access_never_authorizes_cancellation_or_upgrade(): void
    {
        $service = $this->service();
        $reader = User::factory()->create();
        $account = DB::table('billmanager_accounts')->insertGetId(['source_account_id' => 10, 'owner_user_id' => $service->user_id]);
        DB::table('billmanager_members')->insert(['account_id' => $account, 'user_id' => $reader->id, 'source_user_id' => 11]);
        $this->assertTrue(Gate::forUser($reader)->allows('view', $service));
        $this->actingAs($reader);
        Livewire::test(Cancel::class, ['service' => $service])->set('reason', 'Synthetic reason')->call('cancelService')->assertNotFound();
        Livewire::test(Upgrade::class, ['service' => $service])->assertNotFound();
        $component = new Upgrade;
        $component->service = $service;
        try {
            $component->doUpgrade();
            $this->fail('Upgrade action accepted a shared reader');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('service_upgrades', 0);
        }
        $this->assertDatabaseCount('service_cancellations', 0);
    }

    public function test_held_cancellation_model_and_subscription_helper_stop_before_effects(): void
    {
        $service = $this->service();
        $this->hold($service);
        $this->actingAs($service->user);
        $cancel = new Cancel;
        $cancel->service = $service;
        $upgrade = new Upgrade;
        $upgrade->service = $service;
        foreach ([fn () => $cancel->cancelService(), fn () => $upgrade->doUpgrade(), fn () => ServiceCancellation::create(['service_id' => $service->id, 'reason' => 'Synthetic', 'type' => 'immediate']),
            fn () => ExtensionHelper::cancelSubscription($service)] as $action) {
            $blocked = false;
            try {
                $action();
            } catch (MigrationHeldException) {
                $blocked = true;
            }
            $this->assertTrue($blocked);
        }
        $this->assertDatabaseCount('service_cancellations', 0);
    }
}
