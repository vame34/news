<?php

namespace Tests\Feature;

use Database\Seeders\SportRadarSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PriorityNewsPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SportRadarSeeder::class);
    }

    public function test_queue_priority_news_prefers_upcoming_events_with_trend_hits(): void
    {
        DB::table('matches')->where('id', 1001)->update([
            'kickoff_at' => now()->addHours(8),
        ]);

        DB::table('trend_queries')->insert([
            [
                'source' => 'google_trends_rss',
                'query' => 'спартак-москва – динамо москва',
                'locale' => 'ru-RU',
                'trend_score' => 100,
                'observed_date' => now()->toDateString(),
                'observed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'source' => 'google_trends_rss',
                'query' => 'барселона – ньюкасл',
                'locale' => 'ru-RU',
                'trend_score' => 90,
                'observed_date' => now()->toDateString(),
                'observed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->artisan('sport-radar:queue-priority-news --horizon=96 --limit=20')
            ->expectsOutputToContain('priority jobs queued:')
            ->assertExitCode(0);

        $this->assertTrue(DB::table('content_generation_queue')->count() > 0);

        $top = DB::table('content_generation_queue')
            ->orderByDesc('priority_score')
            ->first();

        $this->assertNotNull($top);
        $this->assertTrue(in_array($top->entity_type, ['match_preview_t24', 'match_preview_t1', 'trend_topic', 'match_post_match', 'match_followup'], true));
        $this->assertTrue(DB::table('content_generation_queue')->whereIn('entity_type', ['match_preview_t24', 'match_preview_t1'])->exists());
    }

    public function test_process_priority_news_creates_article_for_future_match(): void
    {
        DB::table('provider_credentials')->update(['is_active' => false]);
        putenv('DEEPSEEK_API_KEY=');
        putenv('DEEPSEEK_DAILY_REQUEST_LIMIT=10');
        putenv('DEEPSEEK_DAILY_TOKEN_LIMIT=10000');

        DB::table('matches')->where('id', 1001)->update([
            'kickoff_at' => now()->addHours(6),
        ]);

        DB::table('content_generation_queue')->insert([
            'match_id' => 1001,
            'entity_type' => 'match_preview_t24',
            'entity_slug' => 'spartak-dinamo-2026-03-21:match_preview_t24',
            'priority_score' => 180,
            'status' => 'queued',
            'trend_hits_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'payload_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'scheduled_for' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('sport-radar:process-priority-news --limit=1')
            ->expectsOutputToContain('priority jobs processed: done=1')
            ->assertExitCode(0);

        $this->assertDatabaseHas('content_generation_queue', [
            'match_id' => 1001,
            'status' => 'done',
        ]);

        $this->assertTrue(DB::table('news')->where('slug', 'preview-spartak-dinamo-2026-03-21')->exists());
    }

    public function test_process_priority_news_skips_past_event(): void
    {
        DB::table('matches')->where('id', 1001)->update([
            'kickoff_at' => now()->subHours(2),
        ]);

        DB::table('content_generation_queue')->insert([
            'match_id' => 1001,
            'entity_type' => 'match_preview_t24',
            'entity_slug' => 'spartak-dinamo-2026-03-21:match_preview_t24',
            'priority_score' => 120,
            'status' => 'queued',
            'trend_hits_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'payload_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'scheduled_for' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('sport-radar:process-priority-news --limit=1')
            ->expectsOutputToContain('priority jobs processed: done=0 skipped=1 failed=0')
            ->assertExitCode(0);

        $this->assertDatabaseHas('content_generation_queue', [
            'match_id' => 1001,
            'status' => 'skipped',
            'result_message' => 'past_event',
        ]);
    }

    public function test_news_endpoint_returns_stored_template_headlines_without_read_side_filter(): void
    {
        DB::table('news')->insert([
            [
                'slug' => 'bad-calendar-headline',
                'title' => 'Оне Кноксвилле против Ричмонд Киккерс: ключевая встреча в календаре лиги',
                'excerpt' => 'Шаблонный текст',
                'body' => 'Шаблонный текст без редакционной ценности.',
                'published_at' => now()->addMinute(),
                'league_slug' => 'football-test',
                'team_slug' => 'one-knoxville',
            ],
            [
                'slug' => 'good-headline',
                'title' => 'Овечкин достиг 1000 голов в НХЛ',
                'excerpt' => 'Нормальный заголовок',
                'body' => 'Редакционный материал о рекорде.',
                'published_at' => now(),
                'league_slug' => 'hockey-test',
                'team_slug' => 'washington',
            ],
        ]);

        $response = $this->getJson('/api/v1/news')
            ->assertOk()
            ->json('data');

        $slugs = array_column($response, 'slug');

        $this->assertContains('good-headline', $slugs);
        $this->assertContains('bad-calendar-headline', $slugs);
    }

    public function test_news_endpoint_returns_stored_generic_fixture_headlines_without_read_side_filter(): void
    {
        DB::table('news')->insert([
            [
                'slug' => 'bad-friendly-headline',
                'title' => 'Молодежные сборные Казахстана и Словакии готовятся к товарищескому матчу 27 марта 2026 года',
                'excerpt' => 'Шаблонный текст',
                'body' => 'Слабый предматчевый шаблон.',
                'published_at' => now()->addMinute(),
                'league_slug' => 'football-test',
                'team_slug' => 'kazakhstan-u21',
            ],
            [
                'slug' => 'good-clean-headline',
                'title' => 'Овечкин достиг 1000 голов в НХЛ',
                'excerpt' => 'Нормальный заголовок',
                'body' => 'Редакционный материал о рекорде.',
                'published_at' => now(),
                'league_slug' => 'hockey-test',
                'team_slug' => 'washington',
            ],
        ]);

        $response = $this->getJson('/api/v1/news')
            ->assertOk()
            ->json('data');

        $slugs = array_column($response, 'slug');

        $this->assertContains('good-clean-headline', $slugs);
        $this->assertContains('bad-friendly-headline', $slugs);
    }
}
