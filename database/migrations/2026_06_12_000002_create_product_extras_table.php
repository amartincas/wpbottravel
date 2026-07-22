<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                  ->constrained('products')
                  ->onDelete('cascade');
            $table->string('name', 100)
                  ->comment('Nombre del extra. Ej: Seguro de viaje, transporte adicional');
            $table->text('description')
                  ->nullable()
                  ->comment('Descripción del extra');
            $table->decimal('cost_price', 10, 2)
                  ->comment('Precio de costo que se paga al operador/proveedor por este extra');
            $table->decimal('sale_price', 10, 2)
                  ->comment('Precio que se cobra al cliente por este extra');
            $table->boolean('is_available')
                  ->default(true)
                  ->comment('Si el extra está disponible para ofrecer');
            $table->unsignedSmallInteger('sort_order')
                  ->default(0)
                  ->comment('Orden de presentación en el catálogo');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_extras');
    }
};
