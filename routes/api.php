<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\WorkerController;
use App\Http\Controllers\Api\JobController;
use App\Http\Controllers\Api\MapController;
use App\Http\Controllers\Api\VideoController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\V1\ExpertController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\PresenceController;
use App\Http\Controllers\Api\V1\ServiceRequestController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\NudgeController;
use App\Http\Controllers\Api\V1\DisputeController;
use App\Http\Controllers\Api\V1\FavoritesController;
use App\Http\Controllers\Api\V1\WorkerModeController;

use App\Http\Controllers\Api\WorkerProfileController;
use App\Http\Controllers\Api\FriendsController;
use App\Http\Controllers\Api\NotificationController;

use App\Http\Controllers\Api\V1\TestController;

// Ruta de login para evitar error cuando falla autenticación
Route::get('/login', function () {
    return response()->json(['message' => 'Unauthenticated.'], 401);
})->name('login');

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Social Auth
Route::get('/auth/google', [SocialAuthController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [SocialAuthController::class, 'handleGoogleCallback']);
Route::get('/auth/facebook', [SocialAuthController::class, 'redirectToFacebook']);
Route::get('/auth/facebook/callback', [SocialAuthController::class, 'handleFacebookCallback']);
Route::post('/auth/{provider}/mobile', [SocialAuthController::class, 'mobileLogin']);

// Public endpoints (no auth required) — legacy
Route::get('/map/nearby-workers', [MapController::class, 'nearbyWorkers']);
Route::get('/map/clusters', [MapController::class, 'clusters']);
Route::get('/workers/{worker}', [WorkerController::class, 'show']);
Route::get('/jobs', [JobController::class, 'index']);

// ── API v1 (Geo-First) ──
Route::prefix('v1')->group(function () {
    Route::get('/test/workers', [TestController::class, 'testWorkers']);
    Route::get('/experts/nearby', [ExpertController::class, 'nearby']);
    Route::get('/experts/{expert}', [ExpertController::class, 'show']);
    Route::get('/categories', [CategoryController::class, 'index']);

    // Nudges (frases motivacionales)
    Route::get('/nudges/random', [NudgeController::class, 'random']);
    Route::get('/nudges', [NudgeController::class, 'index']);

    // Presencia (público para heartbeat sin auth por ahora)
    Route::post('/presence/heartbeat', [PresenceController::class, 'heartbeat']);
    Route::post('/presence/offline', [PresenceController::class, 'goOffline']);
    Route::post('/presence/check-stale', [PresenceController::class, 'checkStale']);
    Route::post('/presence/demand-alert', [PresenceController::class, 'demandAlert']);

    // P0-10: Verificación de QR dinámico (público)
    Route::get('/workers/verify/{token}', [WorkerProfileController::class, 'verifyQRToken']);
});

// ── API v1 autenticado ──
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    // Solicitudes de servicio
    Route::post('/requests', [ServiceRequestController::class, 'store']);
    Route::post('/requests/{serviceRequest}/respond', [ServiceRequestController::class, 'respond']);
    Route::post('/requests/{serviceRequest}/complete', [ServiceRequestController::class, 'complete']);
    Route::post('/requests/{serviceRequest}/cancel', [ServiceRequestController::class, 'cancel']);
    Route::post('/requests/{serviceRequest}/pause', [ServiceRequestController::class, 'pause']);
    Route::post('/requests/{serviceRequest}/resume', [ServiceRequestController::class, 'resume']);
    Route::post('/requests/{serviceRequest}/adjust-price', [ServiceRequestController::class, 'adjustPrice']);
    Route::post('/requests/{serviceRequest}/approve-adjustment', [ServiceRequestController::class, 'approveAdjustment']);
    Route::post('/requests/{serviceRequest}/activity', [ServiceRequestController::class, 'updateActivity']);
    Route::get('/requests/mine', [ServiceRequestController::class, 'myRequests']);

    // Sistema de Disputas
    Route::post('/requests/{serviceRequest}/dispute', [DisputeController::class, 'reportIncident']);
    Route::get('/disputes/mine', [DisputeController::class, 'myDisputes']);

    // Sistema de Favoritos
    Route::post('/favorites', [FavoritesController::class, 'addFavorite']);
    Route::delete('/favorites/{workerId}', [FavoritesController::class, 'removeFavorite']);
    Route::get('/favorites/mine', [FavoritesController::class, 'myFavorites']);

    // Chat
    Route::get('/requests/{serviceRequest}/messages', [ChatController::class, 'messages']);
    Route::post('/requests/{serviceRequest}/messages', [ChatController::class, 'send']);
    Route::post('/requests/{serviceRequest}/messages/read', [ChatController::class, 'markRead']);

    // Worker Mode (3 estados: OFF → ACTIVE → LISTENING)
    Route::post('/worker/status', [WorkerModeController::class, 'status']);
    Route::post('/user/toggle-worker-mode', [WorkerModeController::class, 'toggle']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    
    // Notificaciones Push
    Route::post('/notifications/register-token', [NotificationController::class, 'registerToken']);
    Route::post('/notifications/unregister-token', [NotificationController::class, 'unregisterToken']);
    
    // Workers
    Route::apiResource('workers', WorkerController::class)->except(['show']);
    Route::post('/workers/{worker}/availability', [WorkerController::class, 'updateAvailability']);
    Route::post('/workers/{worker}/location', [WorkerController::class, 'updateLocation']);
    Route::get('/workers/{worker}/videos', [WorkerController::class, 'videos']);
    
    // Jobs
    Route::apiResource('jobs', JobController::class)->except(['index']);
    Route::post('/jobs/{job}/apply', [JobController::class, 'apply']);
    Route::post('/jobs/{job}/cancel', [JobController::class, 'cancel']);
    
    // Videos
    Route::get('/workers/{worker}/all-videos', [VideoController::class, 'index']);
    Route::post('/videos', [VideoController::class, 'store']);
    Route::get('/videos/mine', [VideoController::class, 'myVideos']);
    Route::get('/videos/{video}', [VideoController::class, 'show']);
    Route::delete('/videos/{video}', [VideoController::class, 'destroy']);

    // Payments
    Route::post('/payments/intent', [PaymentController::class, 'createIntent']);
    Route::post('/payments/{payment}/confirm', [PaymentController::class, 'confirm']);
    Route::get('/payments/wallet', [PaymentController::class, 'wallet']);
    Route::get('/payments/history', [PaymentController::class, 'history']);

    // Worker Profile (Mi Perfil / Mis Trabajos)
    Route::get('/worker/profile', [WorkerProfileController::class, 'myProfile']);
    Route::post('/worker/cv', [WorkerProfileController::class, 'uploadCv']);
    Route::delete('/worker/cv', [WorkerProfileController::class, 'deleteCv']);
    Route::post('/worker/skills', [WorkerProfileController::class, 'updateSkills']);
    Route::get('/worker/metrics', [WorkerProfileController::class, 'myMetrics']);
    Route::get('/worker/jobs', [WorkerProfileController::class, 'myJobs']);
    Route::post('/worker/video', [WorkerProfileController::class, 'uploadVideo']);
    Route::delete('/worker/video/{videoId}', [WorkerProfileController::class, 'deleteVideo']);
    
    // P0-10: Generar QR dinámico con JWT
    Route::get('/worker/verification/qr', [WorkerProfileController::class, 'generateVerificationQR']);

    // Friends System (Red de Confianza)
    Route::get('/friends/qr', [FriendsController::class, 'generateQrCode']);
    Route::post('/friends/qr/{qrCode}', [FriendsController::class, 'scanQrCode']);
    Route::get('/friends/search', [FriendsController::class, 'searchByNickname']);
    Route::post('/friends/request', [FriendsController::class, 'sendRequest']);
    Route::post('/friends/accept/{friendshipId}', [FriendsController::class, 'acceptRequest']);
    Route::post('/friends/reject/{friendshipId}', [FriendsController::class, 'rejectRequest']);
    Route::post('/friends/block/{friendshipId}', [FriendsController::class, 'blockUser']);
    Route::get('/friends/list', [FriendsController::class, 'listFriends']);
    Route::get('/friends/pending', [FriendsController::class, 'pendingRequests']);
    Route::get('/friends/check/{userId}', [FriendsController::class, 'checkFriendship']);
    Route::post('/friends/sync-contacts', [FriendsController::class, 'syncContacts']);

    // Visibility Toggle
    Route::post('/worker/visibility', [WorkerProfileController::class, 'toggleVisibility']);
    Route::get('/worker/visibility', [WorkerProfileController::class, 'getVisibility']);
});
