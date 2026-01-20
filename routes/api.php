<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\NfcCredentialController;
use App\Http\Controllers\PreEnrollmentController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TelegramController;
use App\Mail\MyTestEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
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
        ->middleware(['auth:sanctum', 'service.token:service:nfc-reader']);
});


Route::post('/telegram/webhook', [TelegramController::class, 'webhook']);


Route::get('/schedules', [ScheduleController::class, 'index']);



/**
 * ___________________________________________________________________________
 * Public Routes
 * ___________________________________________________________________________
 */

Route::post('/pre-enrollment', [PreEnrollmentController::class, 'store'])->middleware('throttle:10,1');

Route::get('/public/folio/{folio}/pdf', [PreEnrollmentController::class, 'downloadPdf'])
    ->name('folio.pdf')
    ->middleware('signed');
