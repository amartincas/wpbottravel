<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WhatsAppController;

// Filament handles the root path, authentication, and dashboard
// No need to define routes here - Filament will intercept and handle them

require __DIR__.'/settings.php';
Route::post('/api/whatsapp/templates/send', [WhatsAppController::class, 'sendManualTemplate'])
    ->middleware('auth');
