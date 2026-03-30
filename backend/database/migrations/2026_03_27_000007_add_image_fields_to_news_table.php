<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table): void {
            $table->string('image_path', 512)->nullable()->after('team_slug');
            $table->string('image_alt', 255)->nullable()->after('image_path');
            $table->string('image_mime', 64)->nullable()->after('image_alt');
            $table->unsignedInteger('image_width')->nullable()->after('image_mime');
            $table->unsignedInteger('image_height')->nullable()->after('image_width');
            $table->unsignedBigInteger('image_size_bytes')->nullable()->after('image_height');
        });
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table): void {
            $table->dropColumn([
                'image_path',
                'image_alt',
                'image_mime',
                'image_width',
                'image_height',
                'image_size_bytes',
            ]);
        });
    }
};

