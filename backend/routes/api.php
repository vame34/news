<?php

use App\Http\Controllers\Api\V1\InternalApiController;
use App\Http\Controllers\Api\V1\PublicApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', [PublicApiController::class, 'health']);

    Route::get('/matches', [PublicApiController::class, 'matches']);
    Route::get('/matches/{slug}', [PublicApiController::class, 'matchBySlug']);

    Route::get('/leagues/{slug}', [PublicApiController::class, 'leagueBySlug']);
    Route::get('/teams/{slug}', [PublicApiController::class, 'teamBySlug']);

    Route::get('/news', [PublicApiController::class, 'news']);
    Route::get('/news/{slug}', [PublicApiController::class, 'newsBySlug']);
    Route::get('/seo/{entityType}/{entitySlug}', [PublicApiController::class, 'seo']);
    Route::get('/content/pages/{entityType}/{entitySlug}', [PublicApiController::class, 'contentPage']);
    Route::get('/content/pages', [PublicApiController::class, 'contentPages']);
    Route::post('/events/click', [PublicApiController::class, 'eventsClick']);

    Route::prefix('/internal')->middleware('internal.token')->group(function (): void {
        Route::post('/generate/match/{id}', [InternalApiController::class, 'generateMatch']);
        Route::post('/reindex/{id}', [InternalApiController::class, 'reindex']);
        Route::post('/reindex-all', [InternalApiController::class, 'reindexAll']);
        Route::get('/reindex/jobs/{id}', [InternalApiController::class, 'reindexJobStatus']);

        Route::post('/content/{id}/publish', [InternalApiController::class, 'publishContent']);
        Route::post('/content/{pageId}/rollback/{version}', [InternalApiController::class, 'rollbackContent']);

        Route::get('/admin/credentials/deepseek', [InternalApiController::class, 'deepseekCredentials']);
        Route::post('/admin/credentials/deepseek', [InternalApiController::class, 'rotateDeepseekCredential']);

        Route::get('/admin/seo', [InternalApiController::class, 'seoList']);
        Route::post('/admin/seo', [InternalApiController::class, 'upsertSeo']);
    });
});
