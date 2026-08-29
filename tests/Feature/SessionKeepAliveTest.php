<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionKeepAliveTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_refresh_their_session_without_csrf_post(): void
    {
        $user = User::create([
            'name' => 'Pengguna Uji',
            'email' => 'session@test.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $this->withSession([\Illuminate\Support\Facades\Auth::guard()->getName() => $user->id])
            ->actingAs($user)
            ->getJson(route('session.keep-alive'))
            ->assertOk()
            ->assertExactJson(['ok' => true]);
    }

    public function test_guest_cannot_keep_a_session_alive(): void
    {
        $this->get(route('session.keep-alive'))
            ->assertRedirect(route('login'));
    }

}
