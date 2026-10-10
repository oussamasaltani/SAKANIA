<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\OwnerController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ChatbotController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\FavoriteController;

// Public routes
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:3,10');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

// Google sign-in, next to the email/password login above rather than beside it.
// The callback is the one URL Google is configured to redirect to, and it is a
// browser navigation rather than an API call: it answers with a redirect to the
// client, which then exchanges the one-time code it carries for a normal token.
Route::prefix('auth/google')->group(function () {
    Route::post('/redirect', [AuthController::class, 'googleRedirect'])->middleware('throttle:10,1');
    Route::get('/callback', [AuthController::class, 'googleCallback'])->middleware('throttle:20,1');
    Route::post('/exchange', [AuthController::class, 'googleExchange'])->middleware('throttle:20,1');
});

// Public property routes
Route::get('/properties', [PropertyController::class, 'index']);
Route::get('/properties/search', [PropertyController::class, 'search']);
Route::get('/properties/{id}', [PropertyController::class, 'show']);

// Public chatbot
Route::post('/chatbot/send', [ChatbotController::class, 'sendMessage'])->middleware('throttle:20,1');

// Authenticated routes
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Profile of the signed-in account, whatever the role.
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'changePassword'])->middleware('throttle:5,1');
    Route::delete('/profile/avatar', [ProfileController::class, 'destroyAvatar']);

    // Favorites
    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::post('/favorites/{propertyId}', [FavoriteController::class, 'store']);
    Route::delete('/favorites/{propertyId}', [FavoriteController::class, 'destroy']);

    // Messages (tenant <-> owner conversations)
    Route::get('/messages', [MessageController::class, 'index']);
    Route::get('/messages/unread/count', [MessageController::class, 'unreadCount']);
    Route::get('/messages/conversations/{conversation}', [MessageController::class, 'show'])->whereNumber('conversation');
    Route::put('/messages/conversations/{conversation}/read', [MessageController::class, 'markAsRead'])->whereNumber('conversation');
    Route::post('/messages', [MessageController::class, 'send'])->middleware('throttle:30,1');

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread/count', [NotificationController::class, 'unreadCount']);
    Route::put('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);

    // Reservations (tenant)
    Route::post('/reservations', [ReservationController::class, 'store'])->middleware(['tenant', 'throttle:10,1']);
    Route::put('/reservations/{id}/cancel', [ReservationController::class, 'cancel'])->middleware('tenant');

    // Payments (tenant)
    Route::get('/payments/checkout/{id}', [PaymentController::class, 'checkout'])->middleware('tenant');
    Route::post('/payments/process', [PaymentController::class, 'process'])->middleware('tenant');
    Route::get('/payments/{id}/success', [PaymentController::class, 'success'])->middleware('tenant');

    // Contracts
    Route::get('/contracts/{id}/download', [ContractController::class, 'download']);

    // Payment receipts (issued for every payment, the contract stays single)
    Route::get('/payments/{id}/receipt', [PaymentController::class, 'receipt']);

    // Tenant routes
    Route::prefix('tenant')->middleware('tenant')->group(function () {
        Route::get('/dashboard', [TenantController::class, 'dashboard']);
        // Kept for the tenant-scoped path; both now serve the shared controller.
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::get('/reservations', [TenantController::class, 'myReservations']);
        Route::get('/contracts', [TenantController::class, 'myContracts']);
        Route::get('/contracts/{id}', [TenantController::class, 'contractDetails']);
        Route::get('/payments', [TenantController::class, 'myPayments']);
    });

    // Owner routes
    Route::prefix('owner')->middleware('owner')->group(function () {
        Route::get('/dashboard', [OwnerController::class, 'dashboard']);
        // Kept for the owner-scoped path; both now serve the shared controller.
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::get('/properties', [OwnerController::class, 'properties']);
        Route::get('/properties/stats', [OwnerController::class, 'propertyStats']);
        Route::get('/properties/{id}', [OwnerController::class, 'getProperty']);
        Route::post('/properties', [OwnerController::class, 'storeProperty']);
        Route::put('/properties/{id}', [OwnerController::class, 'updateProperty']);
        Route::delete('/properties/{id}', [OwnerController::class, 'deleteProperty']);
        Route::get('/reservations', [OwnerController::class, 'reservations']);
        Route::get('/reservations/{id}', [OwnerController::class, 'reservationDetails']);
        Route::put('/reservations/{id}/accept', [OwnerController::class, 'acceptReservation']);
        Route::put('/reservations/{id}/reject', [OwnerController::class, 'rejectReservation']);
        Route::get('/contracts', [OwnerController::class, 'contracts']);
        Route::get('/contracts/{id}', [OwnerController::class, 'contractDetails']);
        Route::get('/payments', [OwnerController::class, 'payments']);
    });

    // Admin routes
    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard']);

        // Profile of the signed-in admin
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);

        // Users
        Route::get('/users', [AdminController::class, 'users']);
        Route::get('/users/{id}', [AdminController::class, 'viewUser']);
        Route::put('/users/{id}/activate', [AdminController::class, 'activateUser']);
        Route::put('/users/{id}/deactivate', [AdminController::class, 'deactivateUser']);
        Route::delete('/users/{id}', [AdminController::class, 'deleteUser']);

        // Properties
        Route::get('/properties', [AdminController::class, 'properties']);
        Route::get('/properties/{id}', [AdminController::class, 'viewProperty']);
        Route::put('/properties/{id}/approve', [AdminController::class, 'approveProperty']);
        Route::put('/properties/{id}/reject', [AdminController::class, 'rejectProperty']);
        Route::delete('/properties/{id}', [AdminController::class, 'deleteProperty']);

        // Reservations
        Route::get('/reservations', [AdminController::class, 'reservations']);
        Route::get('/reservations/{id}', [AdminController::class, 'viewReservation']);
        Route::delete('/reservations/{id}', [AdminController::class, 'deleteReservation']);

        // Contracts
        Route::get('/contracts', [AdminController::class, 'contracts']);
        Route::get('/contracts/{id}', [AdminController::class, 'viewContract']);
        Route::put('/contracts/{id}/status', [AdminController::class, 'updateContractStatus']);
        Route::delete('/contracts/{id}', [AdminController::class, 'deleteContract']);

        // Payments
        Route::get('/payments', [AdminController::class, 'payments']);
        Route::get('/payments/{id}', [AdminController::class, 'viewPayment']);
        Route::put('/payments/{id}/status', [AdminController::class, 'updatePaymentStatus']);
        Route::delete('/payments/{id}', [AdminController::class, 'deletePayment']);
    });
});
