<?php

use App\Http\Controllers\Api\PromptController;
use App\Http\Middleware\EnsureValidAppKey;
use Illuminate\Support\Facades\Route;

Route::middleware(EnsureValidAppKey::class)->group(function () {
    Route::get('/categories', [PromptController::class, 'categories']);
    Route::get('/prompts/analytics', [PromptController::class, 'analytics']);
    Route::get('/prompts/random', [PromptController::class, 'random']);
    Route::get('/prompts/by-category', [PromptController::class, 'byCategory']);
    Route::get('/prompts/search', [PromptController::class, 'search']);
    Route::post('/prompts/{prompt}/events', [PromptController::class, 'registerOutcome']);
});
