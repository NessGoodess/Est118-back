<?php
//controllers
use App\Http\Controllers\Admission\AdmissionCycleController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\NfcCredentialController;
use App\Http\Controllers\PreEnrollmentController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\GeneralAttendanceController;
use App\Http\Controllers\UserController;
//enums
use App\Enums\ServiceAbility;
use App\Http\Controllers\students\GradeLevelController;
//resources
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Resources\UserResource;

/**
 * Routes
 * ___________________________________________________________________________
 */
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
});

/**
 * Attendance
 * ___________________________________________________________________________
 */
Route::prefix('attendance')->middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::get('/last-attendance', [GeneralAttendanceController::class, 'getLastAttendance']);
    Route::get('/all-attendances', [GeneralAttendanceController::class, 'index']);
    Route::get('/recent-readings', [GeneralAttendanceController::class, 'recentReadings']);
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
        Route::get('/', [PreEnrollmentController::class, 'index']);
        Route::get('/{preEnrollment}',[PreEnrollmentController::class, 'show']);
    })->middleware('auth:sanctum', 'verified');
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

    Route::get('/grades', [GradeLevelController::class, 'index']);
        //->middleware('permission:view students');

    Route::get('/grades/{grade_id}', [StudentController::class, 'getStudentsByGrade']);
        

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
});


require __DIR__ . '/service.php';