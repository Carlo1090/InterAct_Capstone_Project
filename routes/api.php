<?php

use App\Http\Controllers\Admin\BatchController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\ProgramController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware(['auth:sanctum', 'role:admin'])
    ->prefix('admin')
    ->group(function () {
        Route::apiResource('users', UserController::class)->except(['destroy']);
        Route::post('users/{user}/deactivate', [UserController::class, 'deactivate']);
        Route::post('users/{user}/reactivate', [UserController::class, 'reactivate']);

        Route::apiResource('departments', DepartmentController::class)->only(['index', 'store', 'show']);
        Route::apiResource('programs', ProgramController::class)->only(['index', 'store', 'show']);
        Route::apiResource('batches', BatchController::class)->only(['index', 'store', 'show', 'update']);
    });
