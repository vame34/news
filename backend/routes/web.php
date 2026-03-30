<?php

use App\Http\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('admin')->group(function (): void {
    Route::get('/login', [AdminController::class, 'loginForm']);
    Route::post('/login', [AdminController::class, 'login']);
    Route::post('/logout', [AdminController::class, 'logout']);

    Route::middleware('admin.auth')->group(function (): void {
        Route::get('/', [AdminController::class, 'dashboard']);
        Route::get('/credentials', [AdminController::class, 'credentials']);
        Route::post('/credentials/rotate', [AdminController::class, 'rotateCredentials']);

        Route::get('/content', [AdminController::class, 'content']);
        Route::post('/content/{id}/publish', [AdminController::class, 'publishContent']);
        Route::post('/content/{pageId}/rollback/{version}', [AdminController::class, 'rollbackContent']);
        Route::post('/content/{pageId}/reindex', [AdminController::class, 'queueReindex']);

        Route::get('/seo', [AdminController::class, 'seo']);
        Route::post('/seo', [AdminController::class, 'saveSeo']);
        Route::get('/audit', [AdminController::class, 'audit']);
    });
});
