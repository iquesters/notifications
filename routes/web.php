<?php

use Illuminate\Support\Facades\Route;
use Iquesters\Notifications\Http\Controllers\LogController;
use Iquesters\Notifications\Http\Controllers\SettingsController;
use Iquesters\Notifications\Http\Controllers\TemplateController;

$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

// Super-admins and org-admins; the controllers limit them to their organisations.
Route::middleware(['web', 'auth', 'ensure.notifications.manager'])->prefix('notifications')->name('notifications.')->group(function () use ($ulid) {
    // Templates (generic table notification-template-table): rows, a record, its sections.
    Route::get('templates/data', [TemplateController::class, 'data'])->name('templates.data');
    Route::get('templates/data/{uid}', [TemplateController::class, 'record'])->where('uid', $ulid)->name('templates.record');
    Route::put('templates/{uid}', [TemplateController::class, 'update'])->where('uid', $ulid)->name('templates.update');
    Route::get('templates/{uid}/preview', [TemplateController::class, 'preview'])->where('uid', $ulid)->name('templates.preview');
    Route::post('templates/{uid}/test', [TemplateController::class, 'test'])->where('uid', $ulid)->name('templates.test');
    Route::post('templates/{uid}/reset', [TemplateController::class, 'reset'])->where('uid', $ulid)->name('templates.reset');

    // Sent messages (generic table notification-log-table).
    Route::get('logs/data', [LogController::class, 'data'])->name('logs.data');
    Route::get('logs/data/{uid}', [LogController::class, 'record'])->where('uid', $ulid)->name('logs.record');
    Route::get('logs/{uid}/summary', [LogController::class, 'summary'])->where('uid', $ulid)->name('logs.summary');
    Route::post('logs/{uid}/resend', [LogController::class, 'resend'])->where('uid', $ulid)->name('logs.resend');

    // Each organisation's email settings (form notification-email-settings).
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('settings/{uid}', [SettingsController::class, 'edit'])->where('uid', $ulid)->name('settings.edit');
    Route::put('settings/{uid}', [SettingsController::class, 'update'])->where('uid', $ulid)->name('settings.update');
    Route::post('settings/{uid}/test', [SettingsController::class, 'test'])->where('uid', $ulid)->name('settings.test');
});
