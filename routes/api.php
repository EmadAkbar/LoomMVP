<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Video\VideoCommentController;
use App\Http\Controllers\Api\V1\Video\VideoController;
use App\Http\Controllers\Api\V1\Video\VideoShareController;
use App\Http\Controllers\Api\V1\Webhook\CloudflareWebhookController;
use App\Http\Middleware\VerifyCloudflareWebhookSignature;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
        });
    });

    Route::middleware('throttle:30,1')->post('/webhooks/cloudflare', CloudflareWebhookController::class)
        ->middleware(VerifyCloudflareWebhookSignature::class);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/videos', [VideoController::class, 'index']);
        Route::post('/videos/upload-url', [VideoController::class, 'createUploadUrl']);
        Route::get('/videos/{video:uuid}', [VideoController::class, 'show']);
        Route::patch('/videos/{video:uuid}', [VideoController::class, 'update']);
        Route::delete('/videos/{video:uuid}', [VideoController::class, 'destroy']);

        Route::get('/videos/{video:uuid}/comments', [VideoCommentController::class, 'index']);
        Route::post('/videos/{video:uuid}/comments', [VideoCommentController::class, 'store']);

        Route::post('/videos/{video:uuid}/shares', [VideoShareController::class, 'store']);
    });

    Route::middleware('throttle:120,1')->get('/share/{share:share_uuid}', [VideoShareController::class, 'show']);
    Route::middleware('throttle:20,1')->post('/share/{share:share_uuid}/verify-password', [VideoShareController::class, 'verifyPassword']);
});
