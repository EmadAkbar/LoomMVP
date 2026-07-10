<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_login_fetch_profile_and_logout(): void
    {
        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'API Tester',
            'email' => 'api-tester@example.com',
            'password' => 'secret1234',
        ]);

        $registerResponse->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'api-tester@example.com');

        $this->assertDatabaseHas('users', [
            'email' => 'api-tester@example.com',
        ]);

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'api-tester@example.com',
            'password' => 'secret1234',
        ]);

        $token = $loginResponse->json('data.token');

        $loginResponse->assertOk()
            ->assertJsonPath('success', true);

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'api-tester@example.com');

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::query()->create([
            'name' => 'Wrong Password User',
            'email' => 'wrong-pass@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'wrong-pass@example.com',
            'password' => 'invalid-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }
}
