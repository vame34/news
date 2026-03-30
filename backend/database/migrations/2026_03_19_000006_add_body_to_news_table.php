<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table): void {
            $table->text('body')->nullable()->after('excerpt');
        });
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table): void {
            $table->dropColumn('body');
        });
    }
};
