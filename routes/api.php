<?php

use App\Http\Controllers\Admin\BatchController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\ProgramController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Coordinator\JournalTemplateController;
use App\Http\Controllers\Student\JournalCalendarController;
use App\Http\Controllers\Student\JournalEntryController;
use App\Http\Controllers\Student\StudentInfoSheetController;
use App\Http\Controllers\Student\WeeklyActivityLogController;
use App\Http\Controllers\Student\WeeklyLogController;
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

Route::middleware(['auth:sanctum', 'role:coordinator'])
    ->prefix('coordinator')
    ->group(function () {
        Route::get('journal-templates', [JournalTemplateController::class, 'index']);
        Route::post('journal-templates', [JournalTemplateController::class, 'store']);
        Route::put('journal-templates/{journalTemplate}', [JournalTemplateController::class, 'update']);
        Route::patch('journal-templates/{journalTemplate}/toggle-active', [JournalTemplateController::class, 'toggleActive']);
    });

Route::middleware(['auth:sanctum', 'role:student'])
    ->prefix('student')
    ->group(function () {
        Route::get('info-sheet', [StudentInfoSheetController::class, 'show']);
        Route::post('info-sheet', [StudentInfoSheetController::class, 'store']);

        Route::get('journal-entries', [JournalEntryController::class, 'index']);
        Route::get('journal-entries/{date}', [JournalEntryController::class, 'show']);
        Route::post('journal-entries', [JournalEntryController::class, 'store']);

        Route::get('journal-calendar', [JournalCalendarController::class, 'index']);

        Route::get('weekly-logs', [WeeklyLogController::class, 'index']);
        Route::get('weekly-logs/{weekStart}', [WeeklyLogController::class, 'show']);
        Route::post('weekly-logs', [WeeklyLogController::class, 'store']);

        Route::get('weekly-activity-logs', [WeeklyActivityLogController::class, 'index']);
        Route::post('weekly-activity-logs', [WeeklyActivityLogController::class, 'store']);
        Route::get('weekly-activity-logs/{weeklyActivityLog}', [WeeklyActivityLogController::class, 'show']);
        Route::put('weekly-activity-logs/{weeklyActivityLog}', [WeeklyActivityLogController::class, 'update']);
        Route::get('weekly-activity-logs/{weeklyActivityLog}/pdf', [WeeklyActivityLogController::class, 'pdf']);
        Route::post('weekly-activity-logs/{weeklyActivityLog}/entries', [WeeklyActivityLogController::class, 'storeEntry']);
        Route::put('weekly-activity-logs/{weeklyActivityLog}/entries/{entry}', [WeeklyActivityLogController::class, 'updateEntry']);
        Route::delete('weekly-activity-logs/{weeklyActivityLog}/entries/{entry}', [WeeklyActivityLogController::class, 'destroyEntry']);
        Route::patch('weekly-activity-logs/{weeklyActivityLog}/entries-reorder', [WeeklyActivityLogController::class, 'reorderEntries']);
    });
