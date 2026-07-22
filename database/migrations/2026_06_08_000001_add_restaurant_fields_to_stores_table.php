<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('advisor_whatsapp', 20)
                  ->nullable()
                  ->after('name')
                  ->comment('Número WhatsApp del asesor que cierra la reserva (con código de país, ej: 573001234567)');

            $table->string('advisor_notification_template', 100)
                  ->nullable()
                  ->after('advisor_whatsapp')
                  ->comment('Nombre de la plantilla Meta aprobada para notificar nuevos leads al asesor');

            $table->string('advisor_notification_template_lang', 10)
                  ->nullable()
                  ->default('es_CO')
                  ->after('advisor_notification_template')
                  ->comment('Código de idioma de la plantilla Meta, ej: es_CO, en_US');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn([
                'advisor_whatsapp',
                'advisor_notification_template',
                'advisor_notification_template_lang',
            ]);
        });
    }
};
