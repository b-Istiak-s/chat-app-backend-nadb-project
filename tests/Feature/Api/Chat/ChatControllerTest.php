<?php

namespace Tests\Feature\Api\Chat;

use App\Models\User;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ChatControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_endpoint_requires_auth(): void
    {
        $this->postJson('/api/chat/messages', ['message' => 'hello'])
            ->assertUnauthorized();
        $this->getJson('/api/chat/messages')
            ->assertUnauthorized();
    }

    public function test_history_endpoint_returns_empty_when_no_messages(): void
    {
        $user = User::factory()->subscribed()->create(['phone' => '01812345678']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/chat/messages');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertSame([], $response->json('data.messages'));
    }

    public function test_stream_endpoint_returns_text_event_stream(): void
    {
        $user = User::factory()->subscribed()->create(['phone' => '01812345678']);

        // Mock the OpenRouter service so we don't hit the network.
        $mock = Mockery::mock(OpenRouterService::class);
        $mock->shouldReceive('chat')
            ->andReturnUsing(function ($messages, $onChunk) {
                $onChunk('Hello');
                $onChunk(' there');

                return 'Hello there';
            });
        $this->app->instance(OpenRouterService::class, $mock);

        $response = $this->actingAs($user, 'sanctum')
            ->post('/api/chat/messages', ['message' => 'hi'], ['Accept' => 'text/event-stream']);

        $response->assertOk();
        $this->assertStringContainsString('text/event-stream', $response->headers->get('content-type'));

        $body = $response->streamedContent();
        $this->assertStringContainsString('data: {"chunk":"Hello"}', $body);
        $this->assertStringContainsString('data: {"chunk":" there"}', $body);
        $this->assertStringContainsString('"done":true', $body);
        $this->assertStringContainsString('"message_id":', $body);
    }

    public function test_stream_validates_message_field(): void
    {
        $user = User::factory()->subscribed()->create(['phone' => '01812345678']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/chat/messages', [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['message']]);
    }
}
