<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->string('wamid')->nullable()->unique()->after('content');
            $table->string('delivery_status')->nullable()->after('wamid');
            $table->text('delivery_error')->nullable()->after('delivery_status');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn(['wamid', 'delivery_status', 'delivery_error']);
        });
    }
};
