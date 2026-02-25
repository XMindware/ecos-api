<?php

use App\Http\Controllers\Api\PromptController;
use Illuminate\Support\Facades\Route;

Route::get('/categories', [PromptController::class, 'categories']);
Route::get('/prompts/random', [PromptController::class, 'random']);
Route::get('/prompts/by-category', [PromptController::class, 'byCategory']);
Route::get('/prompts/search', [PromptController::class, 'search']);
