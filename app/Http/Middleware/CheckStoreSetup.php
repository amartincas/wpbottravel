<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckStoreSetup
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // Verification for cronjob
        if (app()->runningInConsole()) {
            return $next($request);
        }

       // Skip checks for WhatsApp webhook routes  
        if ($request->is('api/whatsapp/webhook/*')) {
            return $next($request);
        }
        
        // Only check for authenticated users accessing Filament
        if (Auth::check() && $request->path() !== 'yes/store-settings') {
            $user = Auth::user();
            
            // Check if user's store has wa_access_token configured
            if ($user->store && empty($user->store->wa_access_token)) {
                // Store the incomplete setup flag in session
                session(['store_setup_incomplete' => true]);
            } else {
                session(['store_setup_incomplete' => false]);
            }
        }

        return $next($request);
    }
}
