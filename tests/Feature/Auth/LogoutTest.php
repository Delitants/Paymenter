<?php

namespace Tests\Feature\Auth;

use App\Models\Ticket;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_log_out_from_a_denied_resource_page(): void
    {
        $owner = User::factory()->create();
        $customer = User::factory()->create();
        $ticket = Ticket::withoutEvents(fn () => Ticket::create(['user_id' => $owner->id, 'subject' => 'Private synthetic ticket', 'status' => 'closed']));
        $session = $this->loginUser($customer);
        $this->actingAs($customer)->withSession($session)
            ->get(route('tickets.show', $ticket))->assertNotFound()
            ->assertSee('action="' . url('/logout') . '"', false)
            ->assertSee('method="POST"', false)
            ->assertSee('name="_token"', false)
            ->assertDontSee('Private synthetic ticket');
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->assertFalse(UserSession::where('ulid', $session['user_session'])->exists());
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_logout_requires_post_and_does_not_remove_another_session(): void
    {
        $customer = User::factory()->create();
        $session = $this->loginUser($customer);
        $otherSession = $this->loginUser($customer);
        $this->actingAs($customer)->withSession($session)->get('/logout')->assertStatus(405);
        $this->assertAuthenticatedAs($customer);
        $this->post('/logout')->assertRedirect('/');
        $this->assertTrue(UserSession::where('ulid', $otherSession['user_session'])->exists());
        $this->assertFalse(UserSession::where('ulid', $session['user_session'])->exists());
    }
}
