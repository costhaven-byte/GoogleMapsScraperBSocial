<?php

use App\Http\Controllers\Api\WorkerApiController;
use Illuminate\Support\Facades\Route;

// Local worker protocol. The worker polls; the server never calls out.
Route::prefix('worker/v1')->middleware(['worker.auth', 'throttle:worker'])->group(function () {
    Route::post('heartbeat', [WorkerApiController::class, 'heartbeat']);
    Route::post('jobs/claim', [WorkerApiController::class, 'claim']);
    Route::get('jobs/{run}/places', [WorkerApiController::class, 'places'])->whereNumber('run');
    Route::post('jobs/{run}/places', [WorkerApiController::class, 'storePlaces'])->whereNumber('run');
    Route::post('jobs/{run}/logs', [WorkerApiController::class, 'logs'])->whereNumber('run');
    Route::post('jobs/{run}/results', [WorkerApiController::class, 'results'])->whereNumber('run');
    Route::post('jobs/{run}/complete', [WorkerApiController::class, 'complete'])->whereNumber('run');
});
