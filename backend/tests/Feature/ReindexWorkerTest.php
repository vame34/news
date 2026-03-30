<?php

namespace Tests\Feature;

use Database\Seeders\SportRadarSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReindexWorkerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SportRadarSeeder::class);
    }

    public function test_process_reindex_command_marks_job_as_done(): void
    {
        $jobId = DB::table('reindex_jobs')->insertGetId([
            'page_id' => 1,
            'status' => 'queued',
            'queued_at' => now(),
            'attempts' => 0,
        ]);

        $this->artisan('sport-radar:process-reindex')
            ->expectsOutput("reindex job #{$jobId} -> done")
            ->assertExitCode(0);

        $this->assertDatabaseHas('reindex_jobs', [
            'id' => $jobId,
            'status' => 'done',
            'attempts' => 1,
        ]);

        $finishedAt = DB::table('reindex_jobs')->where('id', $jobId)->value('finished_at');
        $this->assertNotNull($finishedAt);
    }

    public function test_process_reindex_command_returns_no_jobs_message_when_queue_is_empty(): void
    {
        $this->artisan('sport-radar:process-reindex')
            ->expectsOutput('no queued reindex jobs')
            ->assertExitCode(0);
    }
}
