<?php

namespace Tests\Feature;

use Database\Seeders\SportRadarSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InternalApiTest extends TestCase
{
    use RefreshDatabase;
    private string $internalToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SportRadarSeeder::class);
        $this->internalToken = (string) env('INTERNAL_API_TOKEN', 'dev-internal-token');
    }

    public function test_internal_endpoints_require_token(): void
    {
        $this->postJson('/api/v1/internal/reindex/1')
            ->assertStatus(401)
            ->assertJsonPath('error', 'Unauthorized');
    }

    public function test_reindex_endpoint_queues_job_with_valid_token(): void
    {
        $response = $this->postJson('/api/v1/internal/reindex/1', [], [
            'X-Internal-Token' => $this->internalToken,
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseCount('reindex_jobs', 1);

        $jobId = (int) $response->json('job_id');
        $this->getJson("/api/v1/internal/reindex/jobs/{$jobId}", [
            'X-Internal-Token' => $this->internalToken,
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.id', $jobId)
            ->assertJsonPath('data.status', 'queued');
    }

    public function test_reindex_all_endpoint_queues_jobs_for_all_content_pages(): void
    {
        $pagesCount = DB::table('content_pages')->count();

        $this->postJson('/api/v1/internal/reindex-all', [], [
            'X-Internal-Token' => $this->internalToken,
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('queued_count', $pagesCount);

        $this->assertDatabaseCount('reindex_jobs', $pagesCount);
    }

    public function test_rotate_deepseek_key_creates_active_credential(): void
    {
        $this->postJson('/api/v1/internal/admin/credentials/deepseek', [
            'label' => 'rotation-test',
            'secret' => 'deepseek-secret-test',
        ], [
            'X-Internal-Token' => $this->internalToken,
        ])->assertOk()->assertJsonPath('ok', true);

        $active = DB::table('provider_credentials')
            ->where('provider', 'deepseek')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($active);
        $this->assertSame('rotation-test', $active->label);
    }

    public function test_generate_match_falls_back_when_deepseek_key_is_missing(): void
    {
        DB::table('provider_credentials')->update(['is_active' => false]);
        putenv('DEEPSEEK_API_KEY=');
        putenv('DEEPSEEK_DAILY_REQUEST_LIMIT=10');
        putenv('DEEPSEEK_DAILY_TOKEN_LIMIT=10000');

        $this->postJson('/api/v1/internal/generate/match/1001', [], [
            'X-Internal-Token' => $this->internalToken,
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.source', 'template')
            ->assertJsonPath('data.fallback_reason', 'missing_key');

        $this->assertDatabaseHas('ai_generation_logs', [
            'provider' => 'deepseek',
            'status' => 'blocked',
        ]);
    }

    public function test_generate_match_falls_back_when_budget_cap_is_reached(): void
    {
        putenv('DEEPSEEK_API_KEY=test-key');
        putenv('DEEPSEEK_DAILY_REQUEST_LIMIT=0');
        putenv('DEEPSEEK_DAILY_TOKEN_LIMIT=0');

        $this->postJson('/api/v1/internal/generate/match/1001', [], [
            'X-Internal-Token' => $this->internalToken,
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.source', 'template')
            ->assertJsonPath('data.fallback_reason', 'budget_cap');

        $this->assertDatabaseHas('ai_generation_logs', [
            'provider' => 'deepseek',
            'status' => 'blocked',
        ]);
    }
}
