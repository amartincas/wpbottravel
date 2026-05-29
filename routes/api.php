<?php

use App\Http\Controllers\WhatsAppController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// ── Public webhook routes (no auth — Meta calls these directly) ─────────────
Route::prefix('whatsapp')->group(function () {
    Route::get('/webhook/{store_token}',  [WhatsAppController::class, 'verify']);
    Route::post('/webhook/{store_token}', [WhatsAppController::class, 'handle']);
});


