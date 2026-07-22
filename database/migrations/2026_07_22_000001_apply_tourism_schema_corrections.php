<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Migración correctiva: los archivos de migración originales de stores/
 * products/product_extras/leads/whatsapp_messages ya habían corrido en
 * este servidor antes de renombrarse/editarse para el nicho de turismo
 * (Laravel no vuelve a ejecutar un archivo de migración ya registrado
 * solo porque su contenido cambió). Esta migración aplica exactamente
 * los ALTER TABLE que faltan, de forma idempotente (verifica antes de
 * tocar cada columna) para poder correr `php artisan migrate` sin
 * importar en qué punto intermedio quedó el esquema del servidor.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- stores: quitar bounding box de cobertura ----
        Schema::table('stores', function (Blueprint $table) {
            foreach (['store_bound_north', 'store_bound_south', 'store_bound_east', 'store_bound_west'] as $col) {
                if (Schema::hasColumn('stores', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        // ---- stores: renombrar campos de notificación al asesor ----
        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'store_whatsapp') && !Schema::hasColumn('stores', 'advisor_whatsapp')) {
                $table->renameColumn('store_whatsapp', 'advisor_whatsapp');
            }
        });
        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'store_order_template') && !Schema::hasColumn('stores', 'advisor_notification_template')) {
                $table->renameColumn('store_order_template', 'advisor_notification_template');
            }
        });
        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'store_order_template_lang') && !Schema::hasColumn('stores', 'advisor_notification_template_lang')) {
                $table->renameColumn('store_order_template_lang', 'advisor_notification_template_lang');
            }
        });

        // ---- products: store_price -> cost_price ----
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'store_price') && !Schema::hasColumn('products', 'cost_price')) {
                $table->renameColumn('store_price', 'cost_price');
            }
        });

        // ---- product_extras: restaurant_price -> cost_price ----
        Schema::table('product_extras', function (Blueprint $table) {
            if (Schema::hasColumn('product_extras', 'restaurant_price') && !Schema::hasColumn('product_extras', 'cost_price')) {
                $table->renameColumn('restaurant_price', 'cost_price');
            }
        });

        // ---- leads: delivery_address_or_location -> meeting_point ----
        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'delivery_address_or_location') && !Schema::hasColumn('leads', 'meeting_point')) {
                $table->renameColumn('delivery_address_or_location', 'meeting_point');
            }
        });

        // ---- leads: eliminar columna GPS ----
        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'location')) {
                $table->dropColumn('location');
            }
        });

        // ---- leads: agregar fecha del tour ----
        Schema::table('leads', function (Blueprint $table) {
            if (!Schema::hasColumn('leads', 'tour_date')) {
                $table->date('tour_date')->nullable()->after('meeting_point')
                    ->comment('Fecha del tour o actividad reservada');
            }
        });

        // ---- leads: snapshot de precios de costo ----
        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'product_store_price') && !Schema::hasColumn('leads', 'product_cost_price')) {
                $table->renameColumn('product_store_price', 'product_cost_price');
            }
        });
        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'extras_store_total') && !Schema::hasColumn('leads', 'extras_cost_total')) {
                $table->renameColumn('extras_store_total', 'extras_cost_total');
            }
        });

        // ---- leads: valores de status del flujo viejo -> flujo de handoff ----
        if (Schema::hasColumn('leads', 'status')) {
            DB::table('leads')->whereIn('status', ['aceptado', 'listo', 'despachado'])->update(['status' => 'derivado']);
            DB::table('leads')->where('status', 'entregado')->update(['status' => 'cerrado']);
        }

        // ---- whatsapp_messages: rol 'restaurant' -> 'advisor' ----
        if (Schema::hasColumn('whatsapp_messages', 'role')) {
            DB::statement("ALTER TABLE whatsapp_messages MODIFY COLUMN role ENUM('user','assistant','advisor','system') NOT NULL");
            DB::table('whatsapp_messages')->where('role', 'restaurant')->update(['role' => 'advisor']);
        }
    }

    public function down(): void
    {
        // Corrección de esquema sin vuelta atrás automática — el estado
        // previo (bounding box, nombres viejos) ya no lo modela el código.
    }
};
