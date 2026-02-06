<?php

use App\Http\Controllers\Admission\AdmissionCycleController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\NfcCredentialController;
use App\Http\Controllers\PreEnrollmentController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TelegramController;
use App\Enums\ServiceAbility;
use App\Http\Controllers\GeneralAttendanceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->get('/user', function (Request $request) {
    return $request->user();
});

Route::group(['middleware' => ['auth:sanctum']], function () {
    Route::get('/all-students', [StudentController::class, 'index']);
    Route::get('/class/{schoolClassId}/date/{date}', [AttendanceController::class, 'getClassAttendance']);
    Route::get('/student/{studentId}', [AttendanceController::class, 'getStudentAttendance']);
    Route::post('/record', [AttendanceController::class, 'recordAttendance']);
    Route::get('/report/class/{schoolClassId}', [AttendanceController::class, 'getClassReport']);
    Route::get('/report/student/{studentId}', [AttendanceController::class, 'getStudentReport']);
});



Route::prefix('reader')->group(function () {
    Route::post('/read-event', [NfcCredentialController::class, 'read'])
        ->middleware([
            'auth:sanctum',
            'service.token:' . ServiceAbility::NFC_READER->value,
        ]);
});

Route::prefix('attendance')->group(function () {
    Route::get('/last-attendance', [GeneralAttendanceController::class, 'getLastAttendance']);
    Route::get('/all-attendances', [GeneralAttendanceController::class, 'index']);
});



Route::post('/telegram/webhook', [TelegramController::class, 'webhook']);

Route::get('/schedules', [ScheduleController::class, 'index']);


/**
 * ___________________________________________________________________________
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
    })->middleware('auth:sanctum');

    Route::prefix('pre-enrollments')->group(function () {
        Route::get('/', [PreEnrollmentController::class, 'index']);
        Route::get('/{preEnrollment}',[PreEnrollmentController::class, 'show']);
    })->middleware('auth:sanctum');
});


require __DIR__ . '/service.php';