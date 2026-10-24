<?php

namespace Tests\Feature\Simulation;

use App\Simulation\StandIns\AnthropicStandIn;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Anthropic stand-in answers a stand-in app's model calls with canned
 * replies, in the Messages API's shape, and keeps what it was asked. These
 * talk to a real `php -S` on a loopback port.
 */
class AnthropicStandInTest extends TestCase
{
    private ?AnthropicStandIn $standIn = null;

    protected function tearDown(): void
    {
        $this->standIn?->stop();

        parent::tearDown();
    }

    public function test_a_request_is_answered_with_the_first_canned_reply_it_matches(): void
    {
        $this->standIn = (new AnthropicStandIn([
            ['when' => 'Draft the answers', 'text' => '{"answers": [], "admins": [], "recap": "Met Ana."}'],
            ['when' => 'briefing', 'text' => '{"headline": "Good morning"}'],
        ]))->start();

        $response = Http::withHeaders(['x-api-key' => 'stand-in', 'anthropic-version' => '2023-06-01'])
            ->post($this->standIn->url().'/v1/messages', [
                'model' => 'claude-opus-5-5',
                'messages' => [['role' => 'user', 'content' => 'Draft the answers from this call.']],
            ]);

        $this->assertSame(200, $response->status());
        $this->assertSame('message', $response->json('type'));
        $this->assertSame('end_turn', $response->json('stop_reason'));
        $this->assertSame('claude-opus-5-5', $response->json('model'));
        $this->assertSame('{"answers": [], "admins": [], "recap": "Met Ana."}', $response->json('content.0.text'));
        $this->assertSame('Draft the answers from this call.', $this->standIn->requests()[0]['messages'][0]['content']);
    }

    public function test_a_request_nothing_matches_fails_loudly_and_is_kept(): void
    {
        $this->standIn = (new AnthropicStandIn([['when' => 'briefing', 'text' => '{}']]))->start();

        $response = Http::post($this->standIn->url().'/v1/messages', ['messages' => [['role' => 'user', 'content' => 'Something else']]]);

        $this->assertSame(500, $response->status());
        $this->assertStringContainsString('no canned reply for request 1', $response->json('error.message'));
        $this->assertCount(1, $this->standIn->requests());
    }

    public function test_an_app_is_pointed_at_it_by_its_environment(): void
    {
        $this->standIn = (new AnthropicStandIn)->start();

        $this->assertSame(['ANTHROPIC_BASE_URL' => $this->standIn->url(), 'ANTHROPIC_API_KEY' => 'stand-in'], $this->standIn->env());
        $this->assertMatchesRegularExpression('#^http://127\.0\.0\.1:\d+$#', $this->standIn->url());
    }
}
