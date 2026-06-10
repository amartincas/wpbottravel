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
            $table->renameColumn('preferred_date_time', 'total_amount');

            // Coordenadas GPS enviadas por el cliente via WhatsApp
            // Formato: "lat,lng" — ej: "3.4516,-76.5320"
            $table->string('location', 100)
                  ->nullable()
                  ->after('delivery_address_or_location')
                  ->comment('Coordenadas GPS enviadas por el cliente. Formato: lat,lng');

            // Estado del ciclo de vida del pedido
            // Actualizado por el restaurante via botón o comando de texto
            $table->string('status', 30)
                  ->default('pendiente')
                  ->after('total_amount')
                  ->comment('Estado del pedido: pendiente, aceptado, en_preparacion, listo, entregado, cancelado');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->renameColumn('total_amount', 'preferred_date_time');
            $table->dropColumn(['location', 'status']);
        });
    }
};
