<?php

namespace Tests\Feature;

use Database\Seeders\SportRadarSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SportRadarSeeder::class);
    }

    public function test_health_endpoint_returns_ok_status(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'sport-radar-backend');
    }

    public function test_matches_endpoint_returns_only_published_match_pages(): void
    {
        $response = $this->getJson('/api/v1/matches')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'slug', 'league', 'home_team', 'away_team']]]);

        $items = $response->json('data');
        $this->assertIsArray($items);
        $this->assertCount(1, $items);
        $this->assertSame('spartak-dinamo-2026-03-21', $items[0]['slug']);
    }

    public function test_unknown_match_returns_404(): void
    {
        $this->getJson('/api/v1/matches/unknown-match')
            ->assertStatus(404)
            ->assertJsonPath('error', 'Match not found');
    }

    public function test_news_article_endpoint_returns_full_body(): void
    {
        $response = $this->getJson('/api/v1/news/spartak-dinamo-preview')
            ->assertOk()
            ->assertJsonPath('data.slug', 'spartak-dinamo-preview');

        $this->assertStringContainsString('Спартак — Динамо', (string) $response->json('data.body'));
    }
}
