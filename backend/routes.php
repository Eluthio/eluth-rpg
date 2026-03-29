<?php
// $pluginPath is provided by the community server plugin loader
// All routes here are inside the auth.central middleware group
// and are automatically prefixed with /api/plugins/rpg/

use Illuminate\Support\Facades\Route;

require_once $pluginPath . '/controller.php';

Route::prefix('/api/plugins/rpg')->middleware(['api', 'auth.central'])->group(function () {
    // Session lifecycle
    Route::get('/channel/{channelId}/session', [RpgController::class, 'channelSession']);
    Route::post('/sessions',                   [RpgController::class, 'createSession']);
    Route::get('/sessions/{id}',               [RpgController::class, 'getSession']);
    Route::post('/sessions/{id}/start',        [RpgController::class, 'startSession']);
    Route::delete('/sessions/{id}',            [RpgController::class, 'endSession']);

    // Players
    Route::post('/sessions/{id}/join',         [RpgController::class, 'joinSession']);
    Route::delete('/sessions/{id}/leave',      [RpgController::class, 'leaveSession']);

    // State (polling)
    Route::get('/sessions/{id}/state',         [RpgController::class, 'getState']);

    // Rolls
    Route::post('/sessions/{id}/rolls',        [RpgController::class, 'createRoll']);
    Route::post('/sessions/{id}/queue',        [RpgController::class, 'requestRoll']);
    Route::delete('/sessions/{id}/queue/{queueId}', [RpgController::class, 'cancelQueueItem']);

    // Messages
    Route::post('/sessions/{id}/messages',     [RpgController::class, 'sendMessage']);

    // Characters
    Route::put('/sessions/{id}/characters',    [RpgController::class, 'saveCharacter']);

    // Templates
    Route::get('/sessions/{id}/templates',     [RpgController::class, 'getTemplates']);
    Route::post('/sessions/{id}/templates',    [RpgController::class, 'createTemplate']);
    Route::put('/sessions/{id}/templates/{templateId}', [RpgController::class, 'updateTemplate']);
});
