<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MidtransWebhookController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Products (public)
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product}', [ProductController::class, 'show']);

// Midtrans webhook (public but verified)
Route::post('/midtrans/webhook', [MidtransWebhookController::class, 'handle']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    
    // Payment Methods
    Route::get('/payment-methods', [PaymentController::class, 'getPaymentMethods']);
    
    // Payments
    Route::post('/orders/{order}/payments', [PaymentController::class, 'createPayment']);
    Route::post('/payments/{payment}/cancel', [PaymentController::class, 'cancelPayment']);
    Route::get('/payments/{payment}/status', [PaymentController::class, 'checkStatus']);
});