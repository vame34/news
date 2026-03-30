<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leagues', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('sport', 64);
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->foreignId('league_id')->constrained('leagues');
        });

        Schema::create('matches', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->foreignId('league_id')->constrained('leagues');
            $table->foreignId('home_team_id')->constrained('teams');
            $table->foreignId('away_team_id')->constrained('teams');
            $table->timestamp('kickoff_at');
            $table->string('status', 64);
            $table->text('analysis');
            $table->json('where_to_watch');
        });

        Schema::create('news', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->text('title');
            $table->text('excerpt');
            $table->timestamp('published_at');
            $table->string('league_slug', 128);
            $table->string('team_slug', 128);
        });

        Schema::create('provider_credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64);
            $table->string('label', 128);
            $table->text('secret_encrypted');
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_validated_at')->nullable();
            $table->string('last_validation_status', 32)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('content_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 64);
            $table->string('entity_slug', 255);
            $table->string('status', 64);
            $table->integer('version');
            $table->text('title');
            $table->text('body');
            $table->timestamp('updated_at');
        });

        Schema::create('content_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('page_id')->constrained('content_pages');
            $table->integer('version');
            $table->text('title');
            $table->text('body');
            $table->timestamp('created_at');
        });

        Schema::create('seo_meta', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 64);
            $table->string('entity_slug', 255);
            $table->text('title');
            $table->text('description');
            $table->text('h1');
            $table->text('canonical');
            $table->string('robots', 128);
            $table->timestamps();
        });

        Schema::create('admin_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('actor', 128);
            $table->string('action', 255);
            $table->json('payload_json');
            $table->string('ip', 128);
            $table->timestamp('created_at');
        });

        Schema::create('admin_auth_state', function (Blueprint $table): void {
            $table->smallInteger('id')->primary();
            $table->integer('failed_attempts');
            $table->bigInteger('lock_until');
        });

        Schema::create('reindex_jobs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('page_id');
            $table->string('status', 32);
            $table->timestamp('queued_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reindex_jobs');
        Schema::dropIfExists('admin_auth_state');
        Schema::dropIfExists('admin_audit_logs');
        Schema::dropIfExists('seo_meta');
        Schema::dropIfExists('content_versions');
        Schema::dropIfExists('content_pages');
        Schema::dropIfExists('provider_credentials');
        Schema::dropIfExists('news');
        Schema::dropIfExists('matches');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('leagues');
    }
};
