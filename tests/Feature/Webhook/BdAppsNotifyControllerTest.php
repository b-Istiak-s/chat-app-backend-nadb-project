<?php

namespace Tests\Feature\Webhook;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BdAppsNotifyControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('bdapps.application_id', 'APP_137539');
        config()->set('bdapps.notify_secret', 'test-notify-secret');
        config()->set('bdapps.country_code', '880');
    }

    public function test_rejects_wrong_application_id(): void
    {
        $response = $this->postJson('/api/webhooks/bdapps/notify', [
            'applicationId' => 'APP_OTHER',
            'subscriberId' => 'tel:8801812345678',
            'status' => 'REGISTERED',
        ]);

        $response->assertStatus(401);
    }

    public function test_rejects_missing_secret(): void
    {
        $response = $this->postJson('/api/webhooks/bdapps/notify', [
            'applicationId' => 'APP_137539',
            'subscriberId' => 'tel:8801812345678',
            'status' => 'REGISTERED',
        ]);

        $response->assertStatus(401);
    }

    public function test_applies_status_when_authenticated(): void
    {
        $user = User::factory()->create(['phone' => '01812345678']);

        $response = $this->postJson('/api/webhooks/bdapps/notify', [
            'applicationId' => 'APP_137539',
            'subscriberId' => 'tel:8801812345678',
            'status' => 'REGISTERED',
            'frequency' => 'daily',
        ], [
            'X-Bdapps-Secret' => 'test-notify-secret',
        ]);

        $response->assertOk();
        $response->assertJsonPath('statusCode', 'S1000');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'subscription_status' => 'subscribed',
        ]);
    }

    public function test_unknown_phone_is_acknowledged_with_s1000(): void
    {
        $response = $this->postJson('/api/webhooks/bdapps/notify', [
            'applicationId' => 'APP_137539',
            'subscriberId' => 'tel:8801899999999',
            'status' => 'REGISTERED',
        ], [
            'X-Bdapps-Secret' => 'test-notify-secret',
        ]);

        $response->assertOk();
        $response->assertJsonPath('statusCode', 'S1000');
    }
}
