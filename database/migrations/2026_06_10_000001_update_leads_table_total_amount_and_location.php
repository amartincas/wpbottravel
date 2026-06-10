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
            // En este modelo de negocio no se maneja fecha/hora de entrega;
            // el valor total del pedido (con extras) es lo que necesitamos.
            $table->renameColumn('preferred_date_time', 'total_amount');

            // Campo para coordenadas GPS enviadas por WhatsApp (Tema B)
            // Formato: "lat,lng" — ej: "3.4516,-76.5320"
            // También puede almacenar el nombre del lugar si Meta lo incluye.
            $table->string('location', 100)
                  ->nullable()
                  ->after('delivery_address_or_location')
                  ->comment('Coordenadas GPS enviadas por el cliente via WhatsApp. Formato: lat,lng');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->renameColumn('total_amount', 'preferred_date_time');
            $table->dropColumn('location');
        });
    }
};
