<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * El estado 'derivado' se renombra a 'aceptado' para que coincida con el
 * texto del botón de la plantilla de Meta que el asesor realmente toca
 * ("Aceptado") — evita que el botón diga una cosa y el estado en el panel
 * diga otra.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('leads', 'status')) {
            DB::table('leads')->where('status', 'derivado')->update(['status' => 'aceptado']);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('leads', 'status')) {
            DB::table('leads')->where('status', 'aceptado')->update(['status' => 'derivado']);
        }
    }
};
