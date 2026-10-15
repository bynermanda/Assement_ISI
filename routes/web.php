<?php

use App\Http\Controllers\A2GuidelineController;
use App\Http\Controllers\AdminAssessmentController;
use App\Http\Controllers\Assessment2Controller;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AssessmentImportController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BulkAssessmentController;
use App\Http\Controllers\CriteriaDescriptionController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeImportController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\EnsurePasswordIsChanged;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    // 5 tries per minute per username + IP, 60 per IP (see AppServiceProvider)
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.attempt');
});

/*
|--------------------------------------------------------------------------
| Authenticated
|--------------------------------------------------------------------------
| throttle:writes = at most 60 POST / PUT / PATCH / DELETE per minute per account (page views are not limited).
*/
Route::middleware(['auth', 'throttle:writes'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Every user can change their own password (also reachable when a change is forced)
    Route::get('/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/password', [PasswordController::class, 'update'])->name('password.update');

    // Everything below requires that the password is not a temporary one
    Route::middleware(EnsurePasswordIsChanged::class)->group(function () {

        Route::get('/', [AssessmentController::class, 'dashboard'])->name('dashboard');

        // ---- Admin (HRD) ------------------------------------------------
        Route::middleware('role:admin')->group(function () {
            Route::get('employees/import', [EmployeeImportController::class, 'create'])->name('employees.import');
            Route::post('employees/import', [EmployeeImportController::class, 'store'])->name('employees.import.store');
            Route::resource('employees', EmployeeController::class)->only(['index', 'store', 'update', 'destroy']);
            Route::patch('employees/{employee}/assessment-level', [EmployeeController::class, 'setAssessmentLevel'])->name('employees.assessment-level');
            Route::post('employees/refresh-cache', [EmployeeController::class, 'refreshCache'])->name('employees.refresh-cache');

            Route::post('users/generate', [UserController::class, 'generate'])->name('users.generate');
            Route::post('users/merge', [UserController::class, 'merge'])->name('users.merge');
            Route::post('users/{user}/split', [UserController::class, 'split'])->name('users.split');
            Route::resource('users', UserController::class)->only(['index', 'store', 'update', 'destroy']);
            Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');

            Route::prefix('criteria')->name('criteria.')->group(function () {
                Route::get('/', [CriteriaDescriptionController::class, 'index'])->name('index');
                Route::get('/create', [CriteriaDescriptionController::class, 'create'])->name('create');
                Route::post('/', [CriteriaDescriptionController::class, 'store'])->name('store');
                Route::get('/{id}/edit', [CriteriaDescriptionController::class, 'edit'])->whereNumber('id')->name('edit');
                Route::put('/{id}', [CriteriaDescriptionController::class, 'update'])->whereNumber('id')->name('update');
                Route::delete('/{id}', [CriteriaDescriptionController::class, 'destroy'])->whereNumber('id')->name('destroy');

                // Guidelines > Assessment 2: criteria and participants per unit
                Route::get('/a2', [A2GuidelineController::class, 'index'])->name('a2');
                Route::put('/a2/{unit}/criteria', [A2GuidelineController::class, 'saveCriteria'])->name('a2.criteria');
                Route::put('/a2/{unit}/participants', [A2GuidelineController::class, 'saveParticipants'])->name('a2.participants');
                Route::post('/a2/{unit}/participants', [A2GuidelineController::class, 'addParticipant'])->name('a2.participants.add');
            });

            // Export of APPROVED assessments only; the buttons sit on the Assessments page (A1) and the Assessment 2 page (A2)
            Route::middleware('throttle:exports')->group(function () {
                Route::get('admin/exports/xlsx', [ExportController::class, 'xlsx'])->name('exports.xlsx');
                Route::get('admin/exports/pdf', [ExportController::class, 'pdf'])->name('exports.pdf');
                Route::get('admin/a2/export/xlsx', [ExportController::class, 'a2Xlsx'])->name('a2.export.xlsx');
                Route::get('admin/a2/export/pdf', [ExportController::class, 'a2Pdf'])->name('a2.export.pdf');
            });

            Route::prefix('admin/assessments')->name('admin.assessments.')->group(function () {
                Route::get('/', [AdminAssessmentController::class, 'index'])->name('index');
                Route::get('/import', [AssessmentImportController::class, 'create'])->name('import');
                Route::get('/import/template', [AssessmentImportController::class, 'template'])->name('import.template');
                Route::post('/import', [AssessmentImportController::class, 'store'])->name('import.store');
                Route::get('/create', [AdminAssessmentController::class, 'create'])->name('create');
                Route::post('/', [AdminAssessmentController::class, 'store'])->name('store');
                Route::get('/{assessment}/edit', [AdminAssessmentController::class, 'edit'])->name('edit');
                Route::put('/{assessment}', [AdminAssessmentController::class, 'update'])->name('update');
                Route::delete('/{assessment}', [AdminAssessmentController::class, 'destroy'])->name('destroy');
            });
        });

        // ---- Assessment 2 (leaders): who may assess / approve whom is set per participant (a2_participants),
        //      so an Assessment 1 reviewer account can hold Assessment 2 duties too (one person, one account) ----
        Route::middleware('role:assessor,reviewer')->prefix('a2')->name('a2.')->group(function () {
            Route::get('/', [Assessment2Controller::class, 'home'])->name('home');
            Route::get('/assess', [Assessment2Controller::class, 'index'])->name('index');
            Route::get('/assess/{employee}', [Assessment2Controller::class, 'form'])->name('form');
            Route::post('/assess/{employee}', [Assessment2Controller::class, 'store'])->name('store');
            Route::get('/approval', [Assessment2Controller::class, 'approvals'])->name('approvals');
            Route::post('/approval/approve-selected', [Assessment2Controller::class, 'approveSelected'])->name('approve-selected');
            Route::post('/approval/{a2Assessment}/reject', [Assessment2Controller::class, 'reject'])->name('reject');
        });
        Route::get('a2/view/{a2Assessment}', [Assessment2Controller::class, 'show'])->middleware('role:assessor,reviewer,admin')->name('a2.show');
        Route::get('admin/a2', [Assessment2Controller::class, 'admin'])->middleware('role:admin')->name('a2.admin');

        // ---- Reviewers: Penilai 1 (assess) and Penilai 2 (approve / edit) ----
        // What each person may do follows from their grade (App\Support\ReviewRules); the controller checks it.
        // Assessment 2 accounts too: a special route (a1_routes) can give them Assessment 1 work; the controllers check ReviewRules::dutyStages()
        Route::middleware('role:reviewer,assessor')->group(function () {
            Route::prefix('assessments')->name('assessments.')->group(function () {
                Route::get('/create', [AssessmentController::class, 'create'])->name('create');
                Route::post('/', [AssessmentController::class, 'store'])->name('store');
                Route::get('/bulk', [BulkAssessmentController::class, 'create'])->name('bulk');
                Route::post('/bulk', [BulkAssessmentController::class, 'store'])->middleware('throttle:bulk')->name('bulk.store');
            });

            Route::prefix('approval')->name('approval.')->group(function () {
                Route::get('/', [AssessmentController::class, 'approvalIndex'])->name('index');
                Route::post('/approve-selected', [AssessmentController::class, 'approveSelected'])->name('approve-selected');
                Route::get('/{assessment}/edit', [AssessmentController::class, 'approvalEdit'])->name('edit');
                Route::put('/{assessment}', [AssessmentController::class, 'approvalUpdate'])->name('update');
                Route::post('/{assessment}/approve', [AssessmentController::class, 'approve'])->name('approve');
                Route::post('/{assessment}/reject', [AssessmentController::class, 'reject'])->name('reject');
            });
        });
    });
});
