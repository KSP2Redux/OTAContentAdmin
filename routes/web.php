<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContentDownloadController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\VesselEditorController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::redirect('/', '/admin');
Route::get('/login', [AuthController::class, 'redirect'])->name('auth.login');
Route::get('/auth/callback', [AuthController::class, 'callback'])->name('auth.callback');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('auth.logout');
Route::get('/auth/logout/callback', [AuthController::class, 'logoutCallback'])->name('auth.logout.callback');
Route::post('/auth/backchannel-logout', [AuthController::class, 'backchannelLogout'])
    ->withoutMiddleware(PreventRequestForgery::class);
Route::get('/admin/downloads/{channel}/{path}', ContentDownloadController::class)
    ->where([
        'channel' => 'main-menu-vessels|missions',
        'path' => '[a-z0-9][a-z0-9.-]*\.json',
    ])
    ->middleware(['auth', 'publisher'])
    ->name('content.download');
Route::get('/health/live', [HealthController::class, 'live'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class]);

Route::prefix('admin/vessel-tools')->middleware(['auth', 'publisher'])->group(function (): void {
    Route::post('/convert', [VesselEditorController::class, 'convert'])->name('vessel.editor.convert');
    Route::post('/edit', [VesselEditorController::class, 'edit'])->name('vessel.editor.edit');
    Route::post('/stage', [VesselEditorController::class, 'stage'])->name('vessel.editor.stage');
    Route::get('/published/{path}', [VesselEditorController::class, 'published'])->name('vessel.editor.published');
    Route::get('/draft/{operation}', [VesselEditorController::class, 'draft'])->name('vessel.editor.draft');
    Route::get('/models', [VesselEditorController::class, 'catalog'])->name('vessel.models.catalog');
    Route::get('/models/{part}/{pose}', [VesselEditorController::class, 'model'])->name('vessel.models.file');
});
Route::get('/health/ready', [HealthController::class, 'ready'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class]);
