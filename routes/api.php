<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BorrowerController;
use App\Http\Controllers\LenderController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\LoanOfficerController;
use App\Http\Controllers\PaymentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json(['status' => 'OK']);
});

// Public routes
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth routes
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('/auth/resend-email-verification', [AuthController::class, 'resendEmailVerification']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Loan statistics (must be before {loan} route to avoid conflicts)
    Route::get('/loans/statistics/user', [LoanController::class, 'statistics']);

    // Loan CRUD
    Route::get('/loans', [LoanController::class, 'index']);
    Route::post('/loans', [LoanController::class, 'store']);
    Route::get('/loans/{loan}', [LoanController::class, 'show']);
    Route::put('/loans/{loan}', [LoanController::class, 'update']);

    // Loan actions
    Route::post('/loans/{loan}/approve', [LoanController::class, 'approve']);
    Route::post('/loans/{loan}/reject', [LoanController::class, 'reject']);
    Route::post('/loans/{loan}/activate', [LoanController::class, 'activate']);

    // Document download
    Route::get('/loans/{loan}/documents/{document}/download', [LoanController::class, 'downloadDocument']);

    // Payment routes - specific routes MUST come before parameterized routes
    Route::get('/payments/upcoming', [PaymentController::class, 'upcoming']);
    Route::get('/payments/overdue', [PaymentController::class, 'overdue']);
    Route::get('/payments', [PaymentController::class, 'adminIndex']);
    Route::post('/payments', [PaymentController::class, 'recordPayment']);
    Route::post('/payments/{payment}/verify', [PaymentController::class, 'verifyPayment']);
    Route::get('/loans/{loan}/payments', [PaymentController::class, 'index']);
    Route::post('/loans/{loan}/payments', [PaymentController::class, 'store']);
    Route::get('/payments/{payment}', [PaymentController::class, 'show']);
    Route::get('/payments/{payment}/proof/download', [PaymentController::class, 'downloadProof']);

    // Lenders
    Route::get('/lenders', [LenderController::class, 'index']);

    // Borrower
    Route::get('/borrowers', [BorrowerController::class, 'index']);

    // Get loans for a specific borrower
    Route::get('/borrowers/{borrower}/loans', [LoanController::class, 'getBorrowerLoans']);
    Route::get('/borrowers/{borrower}', [BorrowerController::class, 'show']);

});
