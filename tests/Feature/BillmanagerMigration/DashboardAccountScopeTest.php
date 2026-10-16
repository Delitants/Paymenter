<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Qirolab\Theme\Theme;
use Tests\TestCase;

class DashboardAccountScopeTest extends TestCase
{
    use RefreshDatabase;

    public static function customerScopes(): array
    {
        return ['shared member' => ['member', 2], 'account owner' => ['owner', 1], 'unrelated native customer' => ['foreign', 1]];
    }

    #[DataProvider('customerScopes')]
    public function test_dashboard_badges_count_visible_account_records_and_exclude_other_accounts(string $viewer, int $expected): void
    {
        // Owner-only counting undercounts shared customers; global counting leaks other accounts.
        Http::preventStrayRequests();
        Mail::fake();
        config(['settings.mail_must_verify' => false, 'settings.theme' => 'default']);
        Theme::set('default', 'default');
        $this->withoutVite();
        $users = ['owner' => User::factory()->create(), 'member' => User::factory()->create(), 'foreign' => User::factory()->create()];
        $account = DB::table('billmanager_accounts')->insertGetId(['source_account_id' => 10, 'owner_user_id' => $users['owner']->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['owner' => 11, 'member' => 12] as $name => $sourceId) {
            DB::table('billmanager_members')->insert(['account_id' => $account, 'user_id' => $users[$name]->id, 'source_user_id' => $sourceId]);
        }
        $product = $this->createProduct();
        $services = [];
        foreach ($users as $name => $user) {
            $services[$name] = Service::factory()->create(['user_id' => $user->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => 'active']);
            Ticket::factory()->create(['user_id' => $user->id, 'status' => 'open']);
            Invoice::factory()->create(['user_id' => $user->id, 'status' => 'pending']);
        }
        Service::factory()->create(['user_id' => $users['owner']->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'status' => 'suspended']);
        Ticket::factory()->create(['user_id' => $users['owner']->id, 'status' => 'closed']);
        Invoice::factory()->create(['user_id' => $users['owner']->id, 'status' => 'cancelled']);

        $user = $users[$viewer];
        $response = $this->actingAs($user)->withSession($this->loginUser($user))->get(route('dashboard'))->assertOk();
        $response->assertSee(route('services.show', $services[$viewer]), false);
        $response->assertDontSee(route('services.show', $services[$viewer === 'foreign' ? 'owner' : 'foreign']), false);
        if ($viewer === 'member') {
            $response->assertSee(route('services.show', $services['owner']), false);
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        foreach (['dashboard.active_services', 'dashboard.open_tickets', 'dashboard.unpaid_invoices'] as $label) {
            $nodes = $xpath->query('//h2[normalize-space(.)="' . __($label) . '"]/ancestor::div[contains(concat(" ", normalize-space(@class), " "), " justify-between ")][1]/span');
            $this->assertSame(1, $nodes->length, 'The native dashboard must expose one badge for ' . $label);
            $this->assertSame((string) $expected, trim($nodes->item(0)->textContent), 'Badge must match visible status-filtered records: ' . $label);
        }
    }
}
