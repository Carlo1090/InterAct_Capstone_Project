<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BatchController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\ProgramController;
use App\Http\Controllers\Admin\StudentInfoSheetController as AdminStudentInfoSheetController;
use App\Http\Controllers\Admin\SystemSettingController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WeeklyBundlingController;
use App\Http\Controllers\Coordinator\AnnualSippReportController;
use App\Http\Controllers\Coordinator\BatchController as CoordinatorBatchController;
use App\Http\Controllers\Coordinator\BatchRosterController;
use App\Http\Controllers\Coordinator\CoordinatorCompanyController;
use App\Http\Controllers\Coordinator\CoordinatorDashboardController;
use App\Http\Controllers\Coordinator\CoordinatorInfoSheetController;
use App\Http\Controllers\Coordinator\CoordinatorJournalActivityController;
use App\Http\Controllers\Coordinator\CoordinatorWeeklyJournalController;
use App\Http\Controllers\Coordinator\EnrollmentController;
use App\Http\Controllers\Coordinator\HteReportController;
use App\Http\Controllers\Coordinator\JournalTemplateController;
use App\Http\Controllers\Student\JournalCalendarController;
use App\Http\Controllers\Student\JournalEntryController;
use App\Http\Controllers\Student\PasswordController;
use App\Http\Controllers\Student\StudentInfoSheetController;
use App\Http\Controllers\Student\WeeklyActivityLogController;
use App\Http\Controllers\Student\WeeklyLogController;
use App\Http\Controllers\Supervisor\SupervisorDashboardController;
use App\Http\Controllers\Supervisor\SupervisorInternController;
use App\Http\Controllers\Supervisor\SupervisorJournalController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    $user = $request->user()->load('program.department');

    // Drives the frontend info-sheet gate for students (backend enforces it too).
    if ($user->isStudent()) {
        $user->setAttribute('student_gated', $user->isInfoSheetGated());
    }

    return response()->json($user);
});

Route::middleware(['auth:sanctum', 'role:admin'])
    ->prefix('admin')
    ->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::put('users/{user}', [UserController::class, 'update']);
        Route::patch('users/{user}/deactivate', [UserController::class, 'deactivate']);
        Route::patch('users/{user}/activate', [UserController::class, 'activate']);
        Route::patch('users/{user}/temporary-password', [UserController::class, 'issueTemporaryPassword']);

        Route::get('departments', [DepartmentController::class, 'index']);
        Route::get('departments/{department}', [DepartmentController::class, 'show']);
        Route::post('departments', [DepartmentController::class, 'store']);
        Route::put('departments/{department}', [DepartmentController::class, 'update']);
        Route::post('departments/{department}/coordinators', [DepartmentController::class, 'assignCoordinator']);
        Route::delete('departments/{department}/coordinators/{coordinator}', [DepartmentController::class, 'removeCoordinator']);

        Route::get('programs', [ProgramController::class, 'index']);
        Route::get('programs/{program}', [ProgramController::class, 'show']);

        Route::get('batches', [BatchController::class, 'index']);
        Route::get('batches/{batch}', [BatchController::class, 'show']);

        Route::get('info-sheets', [AdminStudentInfoSheetController::class, 'index']);
        Route::get('info-sheets/{student}', [AdminStudentInfoSheetController::class, 'show']);

        Route::get('system-settings', [SystemSettingController::class, 'index']);
        Route::put('system-settings', [SystemSettingController::class, 'update']);

        Route::get('audit-logs', [AuditLogController::class, 'index']);
        Route::get('audit-logs/actions', [AuditLogController::class, 'actions']);
        Route::get('audit-logs/export', [AuditLogController::class, 'export']);

        Route::post('weekly-bundling/run', [WeeklyBundlingController::class, 'run']);
    });

Route::middleware(['auth:sanctum', 'role:coordinator'])
    ->prefix('coordinator')
    ->group(function () {
        Route::get('dashboard', [CoordinatorDashboardController::class, 'index']);
        Route::get('journal-activities', [CoordinatorJournalActivityController::class, 'index']);
        Route::get('journal-activities/{student}/{date}', [CoordinatorJournalActivityController::class, 'show']);

        Route::get('weekly-journals', [CoordinatorWeeklyJournalController::class, 'index']);
        Route::get('weekly-journals/{weeklyLog}', [CoordinatorWeeklyJournalController::class, 'show']);
        Route::get('weekly-journals/{weeklyLog}/pdf', [CoordinatorWeeklyJournalController::class, 'pdf']);

        Route::get('companies', [CoordinatorCompanyController::class, 'index']);
        Route::post('companies', [CoordinatorCompanyController::class, 'store']);
        Route::get('companies/{company}', [CoordinatorCompanyController::class, 'show']);
        Route::put('companies/{company}', [CoordinatorCompanyController::class, 'update']);
        Route::post('companies/{company}/supervisors', [CoordinatorCompanyController::class, 'attachSupervisor']);
        Route::post('companies/{company}/supervisors/new', [CoordinatorCompanyController::class, 'createSupervisor']);
        Route::delete('companies/{company}/supervisors/{supervisor}', [CoordinatorCompanyController::class, 'detachSupervisor']);

        Route::get('info-sheets', [CoordinatorInfoSheetController::class, 'index']);
        Route::get('info-sheets/{student}', [CoordinatorInfoSheetController::class, 'show']);

        Route::get('journal-templates', [JournalTemplateController::class, 'index']);
        Route::post('journal-templates', [JournalTemplateController::class, 'store']);
        Route::put('journal-templates/{journalTemplate}', [JournalTemplateController::class, 'update']);
        Route::patch('journal-templates/{journalTemplate}/toggle-active', [JournalTemplateController::class, 'toggleActive']);

        Route::get('batches', [CoordinatorBatchController::class, 'index']);
        Route::post('batches', [CoordinatorBatchController::class, 'store']);
        Route::put('batches/{batch}', [CoordinatorBatchController::class, 'update']);

        Route::get('batches/{batch}/roster', [BatchRosterController::class, 'interns']);
        Route::post('batches/{batch}/roster', [BatchRosterController::class, 'add']);
        Route::patch('batches/{batch}/roster/{batchStudent}/drop', [BatchRosterController::class, 'remove']);
        Route::patch('batches/{batch}/roster/{batchStudent}/reactivate', [BatchRosterController::class, 'reactivate']);
        Route::delete('batches/{batch}/roster/{batchStudent}', [BatchRosterController::class, 'destroy']);

        Route::get('users/interns', [EnrollmentController::class, 'interns']);
        Route::get('users/supervisors', [EnrollmentController::class, 'supervisors']);

        Route::get('students/enrollable', [EnrollmentController::class, 'enrollableStudents']);
        Route::get('enrollment-options', [EnrollmentController::class, 'options']);
        Route::post('accounts', [EnrollmentController::class, 'createAccount']);
        Route::get('roster', [EnrollmentController::class, 'roster']);
        Route::post('enrollments', [EnrollmentController::class, 'store']);
        Route::put('enrollments/{batchStudent}', [EnrollmentController::class, 'update']);

        Route::get('annual-sipp', [AnnualSippReportController::class, 'index']);
        Route::get('annual-sipp/{program}', [AnnualSippReportController::class, 'show']);
        Route::post('annual-sipp/{program}', [AnnualSippReportController::class, 'save']);
        Route::get('annual-sipp/{program}/pdf', [AnnualSippReportController::class, 'pdf']);

        Route::get('hte', [HteReportController::class, 'index']);
        Route::get('hte/{academicYear}/pdf', [HteReportController::class, 'pdf']);
        Route::get('hte/{academicYear}', [HteReportController::class, 'show']);
        Route::post('hte/{academicYear}', [HteReportController::class, 'save']);
    });

Route::middleware(['auth:sanctum', 'role:student'])
    ->prefix('student')
    ->group(function () {
        // Always reachable — the info-sheet gateway itself + account essentials.
        Route::get('info-sheet', [StudentInfoSheetController::class, 'show']);
        Route::post('info-sheet', [StudentInfoSheetController::class, 'store']);
        Route::get('companies', [StudentInfoSheetController::class, 'companies']);

        Route::put('password', [PasswordController::class, 'update']);
    });

// Everything else a student does is gated behind an APPROVED info sheet.
Route::middleware(['auth:sanctum', 'role:student', 'infosheet.approved'])
    ->prefix('student')
    ->group(function () {
        Route::get('journal-entries', [JournalEntryController::class, 'index']);
        Route::get('journal-entries/{date}', [JournalEntryController::class, 'show']);
        Route::get('journal-entries/{date}/pdf', [JournalEntryController::class, 'pdf']);
        Route::post('journal-entries', [JournalEntryController::class, 'store']);

        Route::get('journal-calendar', [JournalCalendarController::class, 'index']);

        Route::get('weekly-logs', [WeeklyLogController::class, 'index']);
        Route::get('weekly-logs/{weekStart}', [WeeklyLogController::class, 'show']);
        Route::get('weekly-logs/{weekStart}/pdf', [WeeklyLogController::class, 'pdf']);
        Route::post('weekly-logs', [WeeklyLogController::class, 'store']);
        Route::post('weekly-logs/{weekStart}/submit', [WeeklyLogController::class, 'submit']);

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

Route::middleware(['auth:sanctum', 'role:supervisor'])
    ->prefix('supervisor')
    ->group(function () {
        Route::get('dashboard', [SupervisorDashboardController::class, 'index']);
        Route::get('interns', [SupervisorInternController::class, 'index']);

        Route::get('journals', [SupervisorJournalController::class, 'index']);
        Route::get('journals/{weeklyLog}', [SupervisorJournalController::class, 'show']);
        Route::get('journals/{weeklyLog}/pdf', [SupervisorJournalController::class, 'pdf']);
        Route::post('journals/{weeklyLog}/approve', [SupervisorJournalController::class, 'approve']);
        Route::post('journals/{weeklyLog}/return', [SupervisorJournalController::class, 'returnLog']);
    });
