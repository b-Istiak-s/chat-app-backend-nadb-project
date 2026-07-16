<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bdapps.application_id', 'APP_137539');
        config()->set('bdapps.password', 'test-password');
        config()->set('bdapps.country_code', '880');
        config()->set('bdapps.application_hash', 'ChatApp');
        config()->set('bdapps.base_url', 'https://developer.bdapps.com');
        config()->set('bdapps.otp_request_endpoint', '/subscription/otp/request');
        config()->set('bdapps.otp_verify_endpoint', '/subscription/otp/verify');
        config()->set('bdapps.status_endpoint', '/subscription/getStatus');
        config()->set('bdapps.subscription_endpoint', '/subscription/send');
        config()->set('bdapps.timeout_seconds', 30);
        config()->set('bdapps.verify_ssl', false);
        config()->set('bdapps.success_status_code', 'S1000');
    }

    public function test_start_with_invalid_phone_returns_422(): void
    {
        $response = $this->postJson('/api/auth/start', ['phone' => 'invalid']);

        $response->assertStatus(422);
        $response->assertJsonStructure(['success', 'message', 'errors' => ['phone']]);
    }

    public function test_start_creates_user_and_requests_otp(): void
    {
        Http::fake([
            'developer.bdapps.com/*' => Http::response([
                'referenceNo' => 'REF-999',
                'statusCode' => 'S1000',
                'statusDetail' => 'Success',
            ], 200),
        ]);

        $response = $this->postJson('/api/auth/start', ['phone' => '01812345678']);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'data' => [
                'token' => null,
                'requires_otp' => true,
                'reference_no' => 'REF-999',
            ],
        ]);

        $this->assertDatabaseHas('users', ['phone' => '01812345678']);
    }

    public function test_start_for_subscribed_user_returns_token(): void
    {
        $user = User::factory()->subscribed()->create(['phone' => '01812345678']);

        $response = $this->postJson('/api/auth/start', ['phone' => '01812345678']);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'data' => [
                'requires_otp' => false,
            ],
        ]);

        $this->assertNotNull($response->json('data.token'));
        $this->assertNull($response->json('data.reference_no'));
    }

    public function test_verify_with_correct_otp_marks_user_subscribed(): void
    {
        Http::fake([
            'developer.bdapps.com/*' => Http::response([
                'subscriptionStatus' => 'REGISTERED',
                'statusCode' => 'S1000',
                'statusDetail' => 'Success',
            ], 200),
        ]);

        // First start so a subscription row exists.
        $this->postJson('/api/auth/start', ['phone' => '01812345678'])->assertOk();

        $response = $this->postJson('/api/auth/verify', [
            'phone' => '01812345678',
            'otp' => '1234',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->assertNotNull($response->json('data.token'));
        $this->assertDatabaseHas('users', [
            'phone' => '01812345678',
            'subscription_status' => 'subscribed',
        ]);
    }

    public function test_me_requires_auth(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->subscribed()->create(['phone' => '01812345678']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/auth/me');

        $response->assertOk();
        $response->assertJsonPath('data.phone', '01812345678');
        $response->assertJsonPath('data.subscription_status', 'subscribed');
    }
}
