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
use App\Http\Controllers\Api\V1\WorkerMediaController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\ContactRevealController;
use App\Http\Controllers\Api\V1\TravelModeController;
use App\Http\Controllers\Api\V1\TravelRequestController;
use App\Http\Controllers\Api\V1\DemandMapController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DiagnosticController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ReviewController;

use App\Http\Controllers\Api\WorkerProfileController;
use App\Http\Controllers\Api\FriendsController;
use App\Http\Controllers\Api\NotificationController;

use App\Http\Controllers\Api\V1\TestController;

// Ruta de login para evitar error cuando falla autenticación
Route::get('/login', function () {
    return response()->json(['message' => 'Unauthenticated.'], 401);
})->name('login');

Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');

// Social Auth
Route::get('/auth/google', [SocialAuthController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [SocialAuthController::class, 'handleGoogleCallback']);
Route::get('/auth/facebook', [SocialAuthController::class, 'redirectToFacebook']);
Route::get('/auth/facebook/callback', [SocialAuthController::class, 'handleFacebookCallback']);
Route::post('/auth/{provider}/mobile', [SocialAuthController::class, 'mobileLogin']);

// Broadcasting auth route for Pusher
Route::post('/broadcasting/auth', function (\Illuminate\Http\Request $request) {
    return \Illuminate\Support\Facades\Broadcast::auth($request);
})->middleware('auth:sanctum');

// Public endpoints (no auth required) — legacy
Route::get('/map/nearby-workers', [MapController::class, 'nearbyWorkers']);
Route::get('/map/clusters', [MapController::class, 'clusters']);
Route::get('/workers/{worker}', [WorkerController::class, 'show']);
Route::get('/jobs', [JobController::class, 'index']);

// ── API v1 (Geo-First) ──
Route::prefix('v1')->group(function () {
    Route::get('/test/workers', [TestController::class, 'testWorkers']);
    Route::get('/experts/nearby', [ExpertController::class, 'nearby'])->middleware('throttle:nearby');
    Route::get('/experts/count', [ExpertController::class, 'count'])->middleware('throttle:nearby');
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

    // Búsqueda inteligente (Weighted + Fuzzy)
    Route::get('/search', [SearchController::class, 'search']);

    // Demanda (Publicación Dorada) - Público
    Route::get('/demand/nearby', [DemandMapController::class, 'nearby'])->middleware('throttle:nearby');
    Route::get('/demand/{serviceRequest}', [DemandMapController::class, 'show']);

    // Dashboard de 36 Nodos - Público
    Route::get('/dashboard/feed', [DashboardController::class, 'feed']);
    Route::get('/dashboard/live-stats', [DashboardController::class, 'liveStats']);

    // Diagnóstico del sistema
    Route::get('/diagnostic/check', [DiagnosticController::class, 'check']);

    // Health Check — para UptimeRobot / monitoreo externo
    Route::get('/health', [HealthController::class, 'check']);

    // P0-10: Verificación de QR dinámico (público)
    Route::get('/workers/verify/{token}', [WorkerProfileController::class, 'verifyQRToken']);

    // Flow - Webhooks públicos (sin autenticación)
    Route::get('/payments/flow/confirm', [\App\Http\Controllers\Api\V1\FlowController::class, 'confirm']);
    Route::post('/payments/flow/confirm', [\App\Http\Controllers\Api\V1\FlowController::class, 'confirm']);
    Route::match(['get', 'post'], '/payments/flow/return', [\App\Http\Controllers\Api\V1\FlowController::class, 'retorno']);
});

// ── API v1 autenticado ──
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    // Solicitudes de servicio
    Route::post('/requests', [ServiceRequestController::class, 'store'])->middleware('throttle:requests');
    Route::get('/requests/mine', [ServiceRequestController::class, 'myRequests']);
    Route::get('/requests/{serviceRequest}', [ServiceRequestController::class, 'show']);
    Route::post('/requests/{serviceRequest}/respond', [ServiceRequestController::class, 'respond']);
    Route::post('/requests/{serviceRequest}/complete', [ServiceRequestController::class, 'complete']);
    Route::post('/requests/{serviceRequest}/cancel', [ServiceRequestController::class, 'cancel']);
    Route::post('/requests/{serviceRequest}/pause', [ServiceRequestController::class, 'pause']);
    Route::post('/requests/{serviceRequest}/resume', [ServiceRequestController::class, 'resume']);
    Route::post('/requests/{serviceRequest}/adjust-price', [ServiceRequestController::class, 'adjustPrice']);
    Route::post('/requests/{serviceRequest}/approve-adjustment', [ServiceRequestController::class, 'approveAdjustment']);
    Route::post('/requests/{serviceRequest}/activity', [ServiceRequestController::class, 'updateActivity']);

    // Sistema de Disputas
    Route::post('/requests/{serviceRequest}/dispute', [DisputeController::class, 'reportIncident']);
    Route::get('/disputes/mine', [DisputeController::class, 'myDisputes']);

    // Sistema de Favoritos
    Route::post('/favorites', [FavoritesController::class, 'addFavorite']);
    Route::delete('/favorites/{workerId}', [FavoritesController::class, 'removeFavorite']);
    Route::get('/favorites/mine', [FavoritesController::class, 'myFavorites']);

    // Revelar contacto (créditos / pioneros)
    Route::post('/contact/reveal', [ContactRevealController::class, 'reveal']);
    Route::get('/contact/check/{workerId}', [ContactRevealController::class, 'check']);

    // Chat
    Route::get('/requests/{serviceRequest}/messages', [ChatController::class, 'messages'])->middleware('throttle:chat');
    Route::post('/requests/{serviceRequest}/messages', [ChatController::class, 'send'])->middleware('throttle:chat');
    Route::post('/requests/{serviceRequest}/messages/read', [ChatController::class, 'markRead']);

    // Reseñas (Reviews)
    Route::post('/reviews', [ReviewController::class, 'store']);
    Route::get('/workers/{worker}/reviews', [ReviewController::class, 'index']);
    Route::post('/reviews/{review}/respond', [ReviewController::class, 'respond']);

    // Fotos de Entrega
    Route::post('/requests/{serviceRequest}/delivery-photo', [ServiceRequestController::class, 'uploadDeliveryPhoto']);

    // Gestión de Categorías (Administración)
    Route::get('/categories/all', [CategoryController::class, 'all']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{category}', [CategoryController::class, 'update']);
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

    // Demanda (Publicación Dorada) - Autenticado
    Route::post('/demand/publish', [DemandMapController::class, 'publish'])->middleware('throttle:demand');
    Route::post('/demand/{serviceRequest}/take', [DemandMapController::class, 'take'])->middleware('throttle:demand');

    // Pagos con Flow
    Route::post('/payments/flow/init', [\App\Http\Controllers\Api\V1\FlowController::class, 'iniciar']);
    
    // Historial de Pagos
    Route::get('/payments/history', [\App\Http\Controllers\Api\PaymentController::class, 'history']);

    // Notificaciones
    Route::get('/notifications', [\App\Http\Controllers\Api\V1\NotificationController::class, 'index']);
    Route::post('/notifications/{notification}/read', [\App\Http\Controllers\Api\V1\NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [\App\Http\Controllers\Api\V1\NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{notification}', [\App\Http\Controllers\Api\V1\NotificationController::class, 'destroy']);
    Route::get('/notifications/preferences', [\App\Http\Controllers\Api\V1\NotificationController::class, 'preferences']);
    Route::post('/notifications/preferences', [\App\Http\Controllers\Api\V1\NotificationController::class, 'preferences']);

    // Worker Mode (3 estados: OFF → ACTIVE → LISTENING)
    Route::post('/worker/status', [WorkerModeController::class, 'status'])->middleware('throttle:worker-status');
    Route::post('/user/toggle-worker-mode', [WorkerModeController::class, 'toggle'])->middleware('throttle:worker-status');
    Route::post('/worker/switch-mode', [WorkerModeController::class, 'switchMode'])->middleware('throttle:worker-status');
    
    // Worker Media (CV y Video Currículum)
    Route::post('/worker/cv', [WorkerMediaController::class, 'uploadCV']);
    Route::delete('/worker/cv', [WorkerMediaController::class, 'deleteCV']);
    Route::post('/worker/video', [WorkerMediaController::class, 'uploadVideo']);
    Route::delete('/worker/video', [WorkerMediaController::class, 'deleteVideo']);
    Route::post('/worker/categories', [WorkerMediaController::class, 'updateCategories']);
    Route::get('/worker/me', [WorkerMediaController::class, 'getWorkerData']);
    
    // Worker Experiences
    Route::get('/worker/experiences/suggestions', [\App\Http\Controllers\Api\V1\WorkerExperienceController::class, 'searchSuggestions']);
    Route::get('/worker/experiences', [\App\Http\Controllers\Api\V1\WorkerExperienceController::class, 'index']);
    Route::post('/worker/experiences', [\App\Http\Controllers\Api\V1\WorkerExperienceController::class, 'store']);
    Route::put('/worker/experiences/{experience}', [\App\Http\Controllers\Api\V1\WorkerExperienceController::class, 'update']);
    Route::delete('/worker/experiences/{experience}', [\App\Http\Controllers\Api\V1\WorkerExperienceController::class, 'destroy']);
    Route::post('/worker/bio-tarjeta', [\App\Http\Controllers\Api\V1\WorkerExperienceController::class, 'updateBioTarjeta']);
    
    // Worker Card Data
    Route::get('/worker/card-data', [\App\Http\Controllers\Api\V1\WorkerCardController::class, 'getCardData']);
    
    // MODO VIAJE - Absorción dinámica de necesidades en ruta
    Route::post('/worker/travel-mode/activate', [TravelModeController::class, 'activate']);
    Route::delete('/worker/travel-mode/deactivate', [TravelModeController::class, 'deactivate']);
    Route::get('/worker/travel-mode/active-routes', [TravelModeController::class, 'getActiveRoutes']);
    
    // Travel Requests - Cliente postula necesidad
    Route::post('/travel-requests', [TravelRequestController::class, 'create']);
    Route::get('/travel-requests/{requestId}/matches', [TravelRequestController::class, 'getMatches']);
    Route::post('/travel-requests/{requestId}/accept', [TravelRequestController::class, 'accept']);
    Route::post('/travel-requests/{requestId}/reject', [TravelRequestController::class, 'reject']);
    Route::get('/travel-requests/{requestId}/track', [TravelRequestController::class, 'track']);
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
