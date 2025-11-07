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

// Note: Webhook akan menerima payment_type: "qris" jika dibayar via QRIS scanning
// atau payment_type: "gopay" jika dibayar via GoPay app

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    
    // Payments
    Route::post('/orders/{order}/payments', [PaymentController::class, 'createPayment']);
    Route::post('/orders/{order}/payments/qris', [PaymentController::class, 'createQrisPayment']);
    Route::post('/payments/{payment}/cancel', [PaymentController::class, 'cancelPayment']);
    Route::get('/payments/{payment}/status', [PaymentController::class, 'checkStatus']);
});