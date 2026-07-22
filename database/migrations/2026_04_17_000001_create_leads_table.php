<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('customer_phone');
            $table->string('customer_name')->nullable();
            $table->text('meeting_point')->nullable();
            $table->string('product_service_name')->nullable();
            $table->string('preferred_date_time')->nullable();
            $table->text('summary');
            $table->boolean('is_processed')->default(false);
            $table->timestamps();

            $table->index(['store_id', 'is_processed']);
            $table->index('customer_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
