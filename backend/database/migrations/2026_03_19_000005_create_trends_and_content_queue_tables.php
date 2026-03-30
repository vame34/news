<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trend_queries', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 64);
            $table->string('query', 255);
            $table->string('locale', 16)->default('ru-RU');
            $table->integer('trend_score')->default(0);
            $table->date('observed_date');
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->unique(['source', 'query', 'observed_date']);
            $table->index(['observed_date', 'trend_score']);
        });

        Schema::create('content_generation_queue', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('match_id')->nullable()->constrained('matches')->nullOnDelete();
            $table->string('entity_type', 32)->default('match_preview');
            $table->string('entity_slug', 255);
            $table->integer('priority_score')->default(0);
            $table->string('status', 32)->default('queued');
            $table->json('trend_hits_json')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamp('scheduled_for');
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('result_message')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_for', 'priority_score']);
            $table->index(['entity_type', 'entity_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_generation_queue');
        Schema::dropIfExists('trend_queries');
    }
};
