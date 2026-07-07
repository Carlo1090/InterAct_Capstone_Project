<?php

use App\Http\Controllers\Admin\BatchController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\ProgramController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Student\StudentInfoSheetController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return response()->json($request->user()->load('program.department'));
});

Route::middleware(['auth:sanctum', 'role:admin'])
    ->prefix('admin')
    ->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::put('users/{user}', [UserController::class, 'update']);
        Route::patch('users/{user}/deactivate', [UserController::class, 'deactivate']);

        Route::get('departments', [DepartmentController::class, 'index']);
        Route::post('departments', [DepartmentController::class, 'store']);
        Route::put('departments/{department}', [DepartmentController::class, 'update']);

        Route::get('programs', [ProgramController::class, 'index']);
        Route::post('programs', [ProgramController::class, 'store']);

        Route::get('batches', [BatchController::class, 'index']);
        Route::post('batches', [BatchController::class, 'store']);
        Route::put('batches/{batch}', [BatchController::class, 'update']);
    });

Route::middleware(['auth:sanctum', 'role:student'])
    ->prefix('student')
    ->group(function () {
        Route::get('info-sheet', [StudentInfoSheetController::class, 'show']);
        Route::post('info-sheet', [StudentInfoSheetController::class, 'store']);
    });
