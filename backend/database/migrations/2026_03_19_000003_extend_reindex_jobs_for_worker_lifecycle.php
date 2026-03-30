<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reindex_jobs', function (Blueprint $table): void {
            $table->timestamp('processing_started_at')->nullable()->after('queued_at');
            $table->timestamp('finished_at')->nullable()->after('processing_started_at');
            $table->unsignedSmallInteger('attempts')->default(0)->after('finished_at');
            $table->text('error_message')->nullable()->after('attempts');
        });
    }

    public function down(): void
    {
        Schema::table('reindex_jobs', function (Blueprint $table): void {
            $table->dropColumn([
                'processing_started_at',
                'finished_at',
                'attempts',
                'error_message',
            ]);
        });
    }
};

