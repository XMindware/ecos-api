<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PromptAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected string $appPrivateKey = 'test-private-key';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.client_private_key', $this->appPrivateKey);
        config()->set('app.client_private_key_header', 'X-App-Private-Key');
    }

    public function test_random_prompt_logs_request_and_returns_request_id_header(): void
    {
        $promptId = $this->createPrompt('family');

        $response = $this->withAppKey()->getJson('/api/prompts/random?category=family&language=en');

        $response
            ->assertOk()
            ->assertHeader('X-Prompt-Request-Id')
            ->assertJsonPath('id', $promptId);

        $requestUuid = $response->headers->get('X-Prompt-Request-Id');

        $this->assertDatabaseHas('prompt_events', [
            'request_uuid' => $requestUuid,
            'endpoint' => 'random',
            'event' => 'requested',
            'prompt_id' => null,
        ]);

        $this->assertDatabaseHas('prompt_events', [
            'request_uuid' => $requestUuid,
            'endpoint' => 'random',
            'event' => 'served',
            'prompt_id' => $promptId,
        ]);
    }

    public function test_feedback_endpoint_registers_used_and_preserves_context(): void
    {
        $promptId = $this->createPrompt('travel');

        $response = $this->withAppKey()->postJson("/api/prompts/{$promptId}/events", [
            'event' => 'used',
            'request_uuid' => '64d3c8d5-5dfd-47d7-93de-4d75c6332c78',
            'video_id' => 42,
            'context' => [
                'source' => 'mobile-app',
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.event', 'used')
            ->assertJsonPath('data.prompt_id', $promptId)
            ->assertJsonPath('data.context.video_id', 42)
            ->assertJsonPath('data.context.source', 'mobile-app');

        $this->assertDatabaseHas('prompt_events', [
            'request_uuid' => '64d3c8d5-5dfd-47d7-93de-4d75c6332c78',
            'endpoint' => 'feedback',
            'event' => 'used',
            'prompt_id' => $promptId,
        ]);
    }

    public function test_analytics_endpoint_returns_prompt_and_category_usage(): void
    {
        $familyPromptId = $this->createPrompt('family', 'Tell me about a family dinner?');
        $travelPromptId = $this->createPrompt('travel', 'What trip changed your perspective?');

        DB::table('prompt_events')->insert([
            [
                'request_uuid' => '11111111-1111-1111-1111-111111111111',
                'endpoint' => 'random',
                'event' => 'requested',
                'prompt_id' => null,
                'filters' => json_encode(['category' => 'family', 'language' => 'en']),
                'context' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'request_uuid' => '11111111-1111-1111-1111-111111111111',
                'endpoint' => 'random',
                'event' => 'served',
                'prompt_id' => $familyPromptId,
                'filters' => json_encode(['category' => 'family', 'language' => 'en']),
                'context' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'request_uuid' => '11111111-1111-1111-1111-111111111111',
                'endpoint' => 'feedback',
                'event' => 'used',
                'prompt_id' => $familyPromptId,
                'filters' => json_encode([]),
                'context' => json_encode(['video_id' => 9]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'request_uuid' => '22222222-2222-2222-2222-222222222222',
                'endpoint' => 'search',
                'event' => 'requested',
                'prompt_id' => null,
                'filters' => json_encode(['category' => 'travel', 'language' => 'en']),
                'context' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'request_uuid' => '22222222-2222-2222-2222-222222222222',
                'endpoint' => 'search',
                'event' => 'served',
                'prompt_id' => $travelPromptId,
                'filters' => json_encode(['category' => 'travel', 'language' => 'en']),
                'context' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'request_uuid' => '22222222-2222-2222-2222-222222222222',
                'endpoint' => 'feedback',
                'event' => 'discarded',
                'prompt_id' => $travelPromptId,
                'filters' => json_encode([]),
                'context' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->withAppKey()->getJson('/api/prompts/analytics?language=en');

        $response
            ->assertOk()
            ->assertJsonPath('summary.request_count', 2)
            ->assertJsonPath('summary.served_count', 2)
            ->assertJsonPath('summary.used_count', 1)
            ->assertJsonPath('summary.discarded_count', 1)
            ->assertJsonCount(2, 'top_prompts')
            ->assertJsonCount(2, 'categories');

        $topPrompts = collect($response->json('top_prompts'))->keyBy('id');

        $this->assertSame(1, $topPrompts[$familyPromptId]['times_requested']);
        $this->assertSame(1, $topPrompts[$familyPromptId]['times_used']);
        $this->assertEquals(100.0, $topPrompts[$familyPromptId]['usage_rate']);

        $this->assertSame(1, $topPrompts[$travelPromptId]['times_requested']);
        $this->assertSame(1, $topPrompts[$travelPromptId]['times_discarded']);
        $this->assertEquals(100.0, $topPrompts[$travelPromptId]['discard_rate']);
    }

    public function test_api_rejects_requests_without_valid_app_key(): void
    {
        $this->createPrompt('family');

        $this->getJson('/api/prompts/random?category=family&language=en')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthorized application key.');

        $this->withHeader('X-App-Private-Key', 'wrong-key')
            ->getJson('/api/prompts/random?category=family&language=en')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthorized application key.');
    }

    protected function createPrompt(string $categorySlug, string $text = 'What memory still makes you smile?'): int
    {
        $categoryId = DB::table('categories')->insertGetId([
            'name' => ucfirst($categorySlug),
            'slug' => $categorySlug,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('prompts')->insertGetId([
            'category_id' => $categoryId,
            'text' => $text,
            'language' => 'en',
            'tone' => 'simple',
            'difficulty' => 'medium',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function withAppKey(): static
    {
        return $this->withHeader('X-App-Private-Key', $this->appPrivateKey);
    }
}
