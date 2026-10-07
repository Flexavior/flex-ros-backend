<?php

use App\Http\Controllers\Api\V1\AgreementController;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ConvymesWebhookController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\InboxController;
use App\Http\Controllers\Api\V1\LaunchPlanController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\MarketingController;
use App\Http\Controllers\Api\V1\MicrosoftGraphWebhookController;
use App\Http\Controllers\Api\V1\MicrosoftIntegrationController;
use App\Http\Controllers\Api\V1\MicrosoftMailController;
use App\Http\Controllers\Api\V1\ProductServiceController;
use App\Http\Controllers\Api\V1\SettingsController;
use Illuminate\Support\Facades\Route;

// ConvyMes → CRM real-time relay (HMAC-verified, no Sanctum)
Route::post('webhooks/convymes', ConvymesWebhookController::class);

// Microsoft Graph change notifications (validationToken + mail ingest)
Route::match(['get', 'post'], 'webhooks/microsoft/graph', MicrosoftGraphWebhookController::class);

// OAuth return from Microsoft (browser redirect — no bearer token; state binds user)
Route::get('integrations/microsoft/callback', [MicrosoftIntegrationController::class, 'callback']);

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    // Dashboard
    Route::get('dashboard/metrics', [DashboardController::class, 'metrics']);

    // Leads & pipeline
    Route::apiResource('leads', LeadController::class);
    Route::post('leads/{lead}/engagements', [LeadController::class, 'storeEngagement']);
    Route::post('leads/{lead}/convert', [LeadController::class, 'convert']);

    // Appointments
    Route::apiResource('appointments', AppointmentController::class)->only(['index', 'store', 'update']);

    // Customers & checklists
    Route::apiResource('customers', CustomerController::class)->only(['index', 'show']);
    Route::put('customers/{customer}/checklist/{itemId}', [CustomerController::class, 'toggleChecklist']);

    // Catalogue
    Route::apiResource('products-services', ProductServiceController::class);

    // Agreements (NDA / MoU / Contract)
    Route::apiResource('agreements', AgreementController::class);

    // Launch plans & dependencies
    Route::apiResource('launch-plans', LaunchPlanController::class);
    Route::post('launch-plans/{launchPlan}/dependencies', [LaunchPlanController::class, 'storeDependency']);
    Route::put('launch-dependencies/{dependency}', [LaunchPlanController::class, 'updateDependency']);

    // Marketing
    Route::get('marketing/channels', [MarketingController::class, 'channels']);
    Route::post('marketing/channels', [MarketingController::class, 'storeChannel']);
    Route::get('marketing/campaigns', [MarketingController::class, 'campaigns']);
    Route::post('marketing/campaigns', [MarketingController::class, 'storeCampaign']);
    Route::post('marketing/campaigns/{campaign}/submit', [MarketingController::class, 'submitCampaign']);
    Route::post('marketing/campaigns/{campaign}/decide', [MarketingController::class, 'decideCampaign']);
    Route::get('marketing/posts', [MarketingController::class, 'posts']);
    Route::post('marketing/posts', [MarketingController::class, 'storePost']);
    Route::put('marketing/posts/{post}', [MarketingController::class, 'updatePost']);

    // Omnichannel inbox (ConvyMes — Facebook / Viber / LINE)
    Route::middleware('inbox.agent')->group(function () {
        Route::get('inbox/stats', [InboxController::class, 'stats']);
        Route::post('inbox/sync', [InboxController::class, 'sync']);
        Route::get('inbox/conversations', [InboxController::class, 'index']);
        Route::get('inbox/conversations/{conversation}', [InboxController::class, 'show']);
        Route::post('inbox/conversations/{conversation}/claim', [InboxController::class, 'claim']);
        Route::post('inbox/conversations/{conversation}/assign', [InboxController::class, 'assign']);
        Route::post('inbox/conversations/{conversation}/release', [InboxController::class, 'release']);
        Route::post('inbox/conversations/{conversation}/reply', [InboxController::class, 'reply']);
        Route::put('inbox/conversations/{conversation}/status', [InboxController::class, 'updateStatus']);
        Route::post('inbox/conversations/{conversation}/link', [InboxController::class, 'link']);
    });

    // Microsoft 365 (delegated mail — Business Basic compatible)
    Route::get('integrations/microsoft/status', [MicrosoftIntegrationController::class, 'status']);
    Route::post('integrations/microsoft/connect', [MicrosoftIntegrationController::class, 'connect']);
    Route::delete('integrations/microsoft/disconnect', [MicrosoftIntegrationController::class, 'disconnect']);
    Route::post('leads/{lead}/email', [MicrosoftMailController::class, 'sendLead']);
    Route::post('customers/{customer}/email', [MicrosoftMailController::class, 'sendCustomer']);

    // Settings & stages
    Route::get('stages', [SettingsController::class, 'stages']);
    Route::get('settings/general', [SettingsController::class, 'getGeneral']);
    Route::put('settings/general', [SettingsController::class, 'updateGeneral']);
    Route::get('settings/checklists', [SettingsController::class, 'getChecklists']);
    Route::put('settings/checklists', [SettingsController::class, 'updateChecklists']);
    Route::get('roles', [SettingsController::class, 'roles']);
});
