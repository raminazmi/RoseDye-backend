<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\{
    AuthController,
    ClientController,
    InvoiceController,
    SubscriptionController,
    VerificationController,
    StatisticsController,
    UserController
};

Route::prefix('v1')->group(function () {
    // Authentication
    Route::post('/client-login', [AuthController::class, 'clientLogin']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::post('/verify-phone', [VerificationController::class, 'sendCode']);
    Route::post('/confirm-verification', [VerificationController::class, 'verifyCode']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/verify-temp-token', [AuthController::class, 'verifyTempToken']);
    Route::post('/resend-otp', [AuthController::class, 'resendVerificationCode']);

    // Protected Routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', [UserController::class, 'index']);
        Route::post('/user/avatar', [UserController::class, 'updateAvatar']);
        Route::put('/user/profile', [UserController::class, 'updateProfile']);
        Route::get('/subscriptions/export-pdf', [SubscriptionController::class, 'exportPdf']);

        // Clients
        Route::apiResource('/clients', ClientController::class);
        Route::patch('/clients/{client}/status', [ClientController::class, 'updateStatus']);

        // Invoices
        Route::apiResource('/invoices', InvoiceController::class);
        Route::patch('/invoices/{invoice}/status', [InvoiceController::class, 'updateStatus']);
        Route::post('/invoices/{invoice}/download', [InvoiceController::class, 'download']);
        Route::get('/invoices/{invoice}/download', [InvoiceController::class, 'download']);

        // Subscriptions
        Route::apiResource('subscriptions', SubscriptionController::class);
        Route::post('subscriptions/{subscription}/notify', [SubscriptionController::class, 'sendNotification']);
        Route::get('/subscriptions/{id}', [SubscriptionController::class, 'show']);
        Route::patch('/subscriptions/{subscription}/status', [SubscriptionController::class, 'updateStatus']); // المسار الجديد
        Route::post('subscriptions/{subscription}/renew', [SubscriptionController::class, 'renew']);

        // Statistics
        Route::get('/statistics', [StatisticsController::class, 'index']);
        Route::get('/statistics/weekly', [StatisticsController::class, 'weekly']);
        Route::get('/statistics/lastweek', [StatisticsController::class, 'lastWeek']);
        Route::get('/statistics/monthly', [StatisticsController::class, 'monthly']);
        Route::get('/statistics/daily', [StatisticsController::class, 'daily']);
    });
});
