<?php

namespace App\Http\Controllers;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BorrowerController;
use App\Http\Controllers\LenderController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\LoanOfficerController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SettingsController;
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

    // create User
    Route::post('/users', [AuthController::class, 'createUser']);

    // User CRUD
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::put('/users/{user}', [UserController::class, 'update']);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);


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

    // ========================================
    // PAYMENT ROUTES - Organized by user type
    // ========================================
    
    // LENDER Payment Routes (for viewing/managing payments)
    Route::prefix('lender')->group(function () {
        Route::get('/payments', [PaymentController::class, 'indexForLender']);
        Route::get('/payments/upcoming', [PaymentController::class, 'upcomingForLender']);
        Route::get('/payments/overdue', [PaymentController::class, 'overdueForLender']);
        Route::get('/payments/awaiting-verification', [PaymentController::class, 'awaitingVerificationForLender']);
        Route::get('/payments/rejected', [PaymentController::class, 'rejectedForLender']);
        Route::get('/payments/paid', [PaymentController::class, 'paidForLender']);
    });
    
    // BORROWER Payment Routes (for submitting payments)
    Route::prefix('borrower')->group(function () {
        Route::post('/payments', [PaymentController::class, 'store']);
        Route::get('/payments', [PaymentController::class, 'indexForBorrower']);
    });
    
    // GENERAL Payment Routes (accessible by multiple roles)
    Route::get('/payments/upcoming', [PaymentController::class, 'upcoming']); // Keep for backward compatibility
    Route::get('/payments/overdue', [PaymentController::class, 'overdue']); // Keep for backward compatibility
    Route::post('/payments/{payment}/verify', [PaymentController::class, 'verify']); // Lenders verify payments
    Route::get('/payments/{payment}/proof/download', [PaymentController::class, 'downloadProof']); // Download proof
    Route::get('/payments/{payment}', [PaymentController::class, 'show']); // View single payment
    
    // LOAN-SPECIFIC Payment Routes
    Route::get('/loans/{loan}/payments', [PaymentController::class, 'index']); // Get payments for a specific loan
    Route::post('/loans/{loan}/payments', [PaymentController::class, 'store']); // Create payment for a loan (deprecated, use /borrower/payments)
    
    // LEGACY/ADMIN Payment Routes
    Route::post('/payments', [PaymentController::class, 'recordPayment']); // Admin record payment

    // Loan Officer 
    Route::get('/loan-officers', [LoanOfficerController::class, 'index']);

    // Lenders
    Route::get('/lenders', [LenderController::class, 'index']);

    // Borrower
    Route::get('/borrowers', [BorrowerController::class, 'index']);
    Route::get('/borrowers/{borrower}', [BorrowerController::class, 'show']);

    // User profile routes
    Route::prefix('settings')->group(function () {
        // Current user profile
        Route::get('/', [SettingsController::class, 'index']);
        Route::put('/update-profile', [SettingsController::class, 'updateProfile']);
        Route::put('/update-contact', [SettingsController::class, 'updateContact']);
        Route::put('/change-password', [SettingsController::class, 'changePassword']);
    });
});