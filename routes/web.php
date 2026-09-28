<?php

use App\Http\Controllers\API\NotificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\Auth\GoogleController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Platform healthcheck (render.yaml:5) and the deploy workflow depend on this
// route. It is public by design, so it must disclose nothing.
//
// Original-audit T2-6: on failure this returned
// 'Database unavailable: ' . $e->getMessage(), which leaked the raw driver
// exception text — driver names, hostnames and credential-adjacent strings —
// to any unauthenticated caller who could time a request against a DB outage.
// This route catches the exception itself, so it never reached the global
// handler's app.debug gate in app/Exceptions/Handler.php:57-63.
//
// The healthcheck only distinguishes 200 from 500, so the body carries no
// detail and the exception goes to the log instead.
Route::get('/up', function () {
    try {
        // Runs a lightweight query to register activity on Aiven MySQL
        DB::select('SELECT 1');
        return response('OK', 200);
    } catch (\Throwable $e) {
        Log::error('Healthcheck /up failed: database unavailable', [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
        ]);

        return response('Service unavailable', 500);
    }
});
Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.readAll');
});

// Google OAuth
Route::prefix('auth/google')->group(function () {
    Route::get('/redirect', [GoogleController::class, 'redirect']);
    Route::get('/callback', [GoogleController::class, 'callback']);
});

Route::get('/reset-password', function (Request $request) {
    $token = $request->query('token');
    $email = $request->query('email');

    if (!$token || !$email) {
        return response()->view('errors.invalid-reset-link', [
            'message' => 'Invalid password reset link'
        ], 400);
    }

    return view('auth.reset-password', [
        'token' => $token,
        'email' => urldecode($email)
    ]);
})->name('password.reset');
