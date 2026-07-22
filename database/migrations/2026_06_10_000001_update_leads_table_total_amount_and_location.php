<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Renombrar preferred_date_time → total_amount
            // El valor total de la reserva (con extras) es lo que necesitamos
            // para el handoff al asesor.
            $table->renameColumn('preferred_date_time', 'total_amount');

            // Fecha del tour/actividad reservada — a diferencia de un pedido a
            // domicilio, una reserva turística siempre está atada a una fecha.
            $table->date('tour_date')
                  ->nullable()
                  ->after('meeting_point')
                  ->comment('Fecha del tour o actividad reservada');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->renameColumn('total_amount', 'preferred_date_time');
            $table->dropColumn('tour_date');
        });
    }
};
