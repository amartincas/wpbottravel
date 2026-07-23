<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('origin_city', 100)
                  ->nullable()
                  ->after('meeting_point')
                  ->comment('Ciudad de origen del viajero');

            // Texto libre en vez de entero: el cliente suele responder con
            // desglose ("2 adultos y 3 niños"), no un número simple.
            $table->string('travelers_count', 100)
                  ->nullable()
                  ->after('tour_date')
                  ->comment('Cantidad/composición de viajeros tal como la da el cliente. Ej: "2 adultos y 3 niños"');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['origin_city', 'travelers_count']);
        });
    }
};
