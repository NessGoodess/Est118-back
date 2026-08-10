<?php
//controllers
use App\Http\Controllers\Admission\AdmissionCycleController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\NfcCredentialController;
use App\Http\Controllers\NfcReaderSlotController;
use App\Http\Controllers\Admission\PreEnrollmentController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\GeneralAttendanceController;
use App\Http\Controllers\AttendanceSettingsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\EnrollmentPromotionController;
use App\Http\Controllers\AcademicYearPromotionController;
use App\Http\Controllers\FirstGradeGroupAssignmentController;
//enums
use App\Enums\ServiceAbility;
use App\Http\Controllers\Admission\PreEnrollmentExportController;
use App\Http\Controllers\students\GradeLevelController;
use App\Http\Controllers\students\PrivateImageController;
use App\Http\Controllers\StudentCredentialPrintingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\School\ReEnrollmentPeriodController;
use App\Http\Controllers\School\ReEnrollmentApplicationController;
use App\Http\Controllers\School\AcademicYearController;
//resources
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Resources\UserResource;

/**
 * Routes
 * ___________________________________________________________________________
 */
Route::prefix('notifications')->middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::get('/', [NotificationController::class, 'index']);
    Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
});

Route::middleware(['auth:sanctum', 'verified'])->get('/user', function (Request $request) {
    $user = $request->user();
    $user->load('roles.permissions', 'permissions');

    return new UserResource($user);
});

/**
 * Current User
 * ___________________________________________________________________________
 */
Route::prefix('current-user')->group(function () {
    Route::post('/change-password', [ChangePasswordController::class, 'changePassword']);
})->middleware('auth:sanctum', 'verified');

/**
 * Attendance
 * ___________________________________________________________________________
 */
Route::group(['middleware' => ['auth:sanctum', 'verified']], function () {
    Route::get('/all-students', [StudentController::class, 'index']);
    Route::get('/class/{schoolClassId}/date/{date}', [AttendanceController::class, 'getClassAttendance']);
    Route::get('/student/{studentId}', [AttendanceController::class, 'getStudentAttendance']);
    Route::post('/record', [AttendanceController::class, 'recordAttendance']);
    Route::get('/report/class/{schoolClassId}', [AttendanceController::class, 'getClassReport']);
    Route::get('/report/student/{studentId}', [AttendanceController::class, 'getStudentReport']);
});

/**
 * NFC Reader
 * ___________________________________________________________________________
 */
Route::prefix('reader')->group(function () {
    Route::post('/read-event', [NfcCredentialController::class, 'read'])
        ->middleware([
            'auth:sanctum',
            'service.token:' . ServiceAbility::NFC_READER->value,
        ]);

    Route::middleware([
        'auth:sanctum',
        'verified',
        'permission:manage nfc readings',
    ])->group(function () {
        Route::get('/status', [NfcCredentialController::class, 'readerStatus']);
        Route::get('/slots', [NfcReaderSlotController::class, 'index']);
        Route::get('/config', [NfcReaderSlotController::class, 'config']);
        Route::patch('/slots/{slot}', [NfcReaderSlotController::class, 'update']);
        Route::patch('/slots/{slot}/arm', [NfcReaderSlotController::class, 'arm']);
        Route::post('/slots/{slot}/start-pairing', [NfcReaderSlotController::class, 'startPairing']);
        Route::post('/slots/cancel-pairing', [NfcReaderSlotController::class, 'cancelPairing']);
        Route::post('/slots/arm-all', [NfcReaderSlotController::class, 'armAll']);
    });
});

/**
 * Attendance (school-wide / NFC)
 * ___________________________________________________________________________
 */
Route::prefix('attendance')->middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::middleware('permission:view general attendance')->group(function () {
        Route::get('/daily', [GeneralAttendanceController::class, 'daily']);
        Route::get('/daily-statuses', [GeneralAttendanceController::class, 'dailyStatuses']);
        Route::get('/all-attendances', [GeneralAttendanceController::class, 'index']);
        Route::get('/settings', [AttendanceSettingsController::class, 'show']);
    });

    Route::put('/settings', [AttendanceSettingsController::class, 'update'])
        ->middleware('permission:edit general attendance');

    // Used by live panels (fallback) and viewers
    Route::get('/last-attendance', [GeneralAttendanceController::class, 'getLastAttendance'])
        ->middleware('permission:view general attendance|manage nfc readings');

    // Live panels feed
    Route::get('/recent-readings', [GeneralAttendanceController::class, 'recentReadings'])
        ->middleware('permission:manage nfc readings');
});



Route::post('/telegram/webhook', [TelegramController::class, 'webhook']);

Route::get('/schedules', [ScheduleController::class, 'index']);

Route::get('/debug-telegram-config', function () {
    return [
        'env_token' => env('TELEGRAM_BOT_TOKEN'),
        'config_token' => config('telegram.bots.mybot.token'),
        'default_bot' => config('telegram.default'),
    ];
});

/**
 * Admission Settings Routes
 * ___________________________________________________________________________
 */
Route::prefix('admissions')->group(function () {

    // public routes
    Route::get('/status', [AdmissionCycleController::class, 'status']);

    Route::post('/pre-enrollment', [PreEnrollmentController::class, 'store'])
        ->middleware(['throttle:5,1', 'admissions.active']);

    Route::get('/public/folio/{folio}/pdf', [PreEnrollmentController::class, 'downloadPdf'])
        ->name('folio.pdf')
        ->middleware('signed');

    Route::prefix('cycles')->group(function () {
        Route::get('/', [AdmissionCycleController::class, 'index']);
        Route::post('/', [AdmissionCycleController::class, 'store']);
        Route::patch('/{cycle}/activate', [AdmissionCycleController::class, 'activate']);
        Route::patch('/{cycle}/close', [AdmissionCycleController::class, 'close']);
        Route::patch('/{cycle}/reopen', [AdmissionCycleController::class, 'reopen']);
        Route::delete('/{cycle}', [AdmissionCycleController::class, 'destroy']);
    })->middleware('auth:sanctum', 'verified');

    Route::prefix('pre-enrollments')->group(function () {
        Route::post('/', [PreEnrollmentController::class, 'storeByAdmin']);
        Route::get('/', [PreEnrollmentController::class, 'index']);
        Route::get('/export', [PreEnrollmentExportController::class, 'export']);
        Route::get('/{preEnrollment}', [PreEnrollmentController::class, 'show']);
        Route::patch('/{preEnrollment}', [PreEnrollmentController::class, 'update']);
        Route::patch('/{preEnrollment}/process', [PreEnrollmentController::class, 'updateProcess']);
        Route::post('/{preEnrollment}/convert-student', [PreEnrollmentController::class, 'convertToStudent']);
        Route::post('/{preEnrollment}/resent-pdf-folio', [PreEnrollmentController::class, 'resentPdfFolio']);
    })->middleware('auth:sanctum', 'verified');


    //Promotion routes
    Route::prefix('enrollments')->middleware(['auth:sanctum', 'verified', 'permission:manage admission cycles'])->group(function () {
        Route::get('/pending-decisions', [EnrollmentPromotionController::class, 'pendingDecisions']);
        Route::patch('/{enrollment}/promotion-decision', [EnrollmentPromotionController::class, 'updateDecision']);
        Route::post('/first-grade-group-assignment', [FirstGradeGroupAssignmentController::class, 'assign']);
    });
});

/**
 * Academic years / annual processes
 */
Route::prefix('academic-years')
    ->middleware(['auth:sanctum', 'verified'])
    ->group(function () {
 
        Route::get('/', [AcademicYearController::class, 'index'])
            ->middleware('permission:view academic years|manage re-enrollment|manage admission cycles');
        Route::post('/', [AcademicYearController::class, 'store'])
            ->middleware('permission:create academic years');
        // Edit disabled for now — closed in controller.
        Route::patch('/{academicYear}', [AcademicYearController::class, 'update'])
            ->middleware('permission:create academic years');
        Route::patch('/{academicYear}/activate', [AcademicYearController::class, 'activate'])
            ->middleware('permission:create academic years');
        Route::post('/{academicYear}/generate-groups', [AcademicYearController::class, 'generateGroups'])
            ->middleware('permission:create academic years');
        Route::delete('/{academicYear}', [AcademicYearController::class, 'destroy'])
            ->middleware('permission:delete academic years');
        Route::post('/promote', [AcademicYearPromotionController::class, 'promote'])
            ->middleware('permission:manage admission cycles|manage re-enrollment');
    });

/**
 * School — Re-enrollment process
 */
Route::prefix('school/re-enrollment')
    ->middleware(['auth:sanctum', 'verified', 'permission:manage re-enrollment'])
    ->group(function () {
        Route::get('/periods', [ReEnrollmentPeriodController::class, 'index']);
        Route::post('/periods', [ReEnrollmentPeriodController::class, 'store']);
        Route::get('/periods/{period}', [ReEnrollmentPeriodController::class, 'show']);
        Route::patch('/periods/{period}', [ReEnrollmentPeriodController::class, 'update']);
        Route::patch('/periods/{period}/open', [ReEnrollmentPeriodController::class, 'open']);
        Route::patch('/periods/{period}/close', [ReEnrollmentPeriodController::class, 'close']);
        Route::get('/periods/{period}/dashboard', [ReEnrollmentPeriodController::class, 'dashboard']);
        Route::get('/periods/{period}/history', [ReEnrollmentPeriodController::class, 'history']);
        Route::post('/periods/{period}/advance-step', [ReEnrollmentPeriodController::class, 'advanceStep']);
        Route::post('/periods/{period}/promote', [ReEnrollmentPeriodController::class, 'promote']);
        Route::post('/periods/{period}/finalize', [ReEnrollmentPeriodController::class, 'finalize']);

        Route::get('/periods/{period}/applications', [ReEnrollmentApplicationController::class, 'index']);
        Route::patch('/periods/{period}/applications/{application}', [ReEnrollmentApplicationController::class, 'update']);
    });

/**
 * Announcements (Notices)
 * ___________________________________________________________________________
 */
Route::prefix('announcements')->group(function () {
    // Public endpoints
    Route::get('/', [AnnouncementController::class, 'index']);
    Route::get('/{announcement}', [AnnouncementController::class, 'show']);

    // Management endpoints (authenticated + permission)
    Route::middleware(['auth:sanctum', 'verified', 'permission:create announcements'])->group(function () {
        Route::post('/', [AnnouncementController::class, 'store']);
        Route::patch('/{announcement}', [AnnouncementController::class, 'update']);
        Route::delete('/{announcement}', [AnnouncementController::class, 'destroy']);
    });
});

/**
 * User Management Routes
 * ___________________________________________________________________________
 */
Route::prefix('users')->middleware('auth:sanctum', 'verified')->group(function () {
    Route::get('/', [UserController::class, 'index'])
        ->middleware('permission:view users');

    Route::get('/{user}', [UserController::class, 'show'])
        ->middleware('permission:view users');

    Route::patch('/{user}', [UserController::class, 'update'])
        ->middleware('permission:edit users');

    Route::delete('/{user}', [UserController::class, 'destroy'])
        ->middleware('permission:delete users');

    Route::post('/{user}/change-password', [UserController::class, 'changePassword'])
        ->middleware('permission:edit users');

    Route::post('/{user}/resend-verification', [UserController::class, 'resendVerification'])
        ->middleware('permission:edit users');
});

/**
 * Roles and Permissions
 * ___________________________________________________________________________
 */
Route::middleware('auth:sanctum', 'verified')->group(function () {
    Route::get('/roles', [UserController::class, 'roles']);
    Route::get('/permissions', [UserController::class, 'permissions']);
});


/**
 * student management
 */
Route::prefix('students')->middleware('auth:sanctum', 'verified')->group(function () {

  Route::get('/grades', [GradeLevelController::class, 'index'])
        ->middleware('permission:view students');

    Route::get('/grades/{grade_id}', [StudentController::class, 'getStudentsByGrade']);

    Route::prefix('credentials')->group(function () {
        Route::get('grades/{grade}/class-groups', [StudentCredentialPrintingController::class, 'classGroupsForGrade'])
            ->whereNumber('grade')
            ->middleware('permission:view students');
        Route::get('class-groups/{classGroup}/rows', [StudentCredentialPrintingController::class, 'rows'])
            ->middleware('permission:view students');
        Route::get('class-groups/{classGroup}/export', [StudentCredentialPrintingController::class, 'exportExcel'])
            ->middleware('permission:view students');
        Route::get('class-groups/{classGroup}/photos-zip', [StudentCredentialPrintingController::class, 'photosZip'])
            ->middleware('permission:view students');
        Route::patch('{student}/tracking', [StudentCredentialPrintingController::class, 'updateTracking'])
            ->whereNumber('student')
            ->middleware('permission:edit students');
    });

    Route::get('/', [StudentController::class, 'index'])
        ->middleware('permission:view students');

    Route::get('/{student}', [StudentController::class, 'show'])
        ->middleware('permission:view students');

    Route::patch('/{student}', [StudentController::class, 'update'])
        ->middleware('permission:edit students');

    Route::delete('/{student}', [StudentController::class, 'destroy'])
        ->middleware('permission:delete students');

    Route::post('/{student}/change-password', [StudentController::class, 'changePassword'])
        ->middleware('permission:edit students');

    Route::post('/{student}/resend-verification', [StudentController::class, 'resendVerification'])
        ->middleware('permission:edit students');

    Route::get('/{student}/photo-status', [StudentController::class, 'photoStatus'])
        ->middleware('permission:view student photos|manage student photos');

    Route::post('/{student}/photo', [StudentController::class, 'uploadPhoto'])
        ->middleware('permission:manage student photos');
});


Route::get('/private-image/{id}', [PrivateImageController::class, 'showById'])
    ->whereNumber('id')
    ->middleware('signed')
    ->name('private.image');



require __DIR__ . '/service.php';
