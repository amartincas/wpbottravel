<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ampliar el enum para incluir advisor y system
        DB::statement("ALTER TABLE whatsapp_messages MODIFY COLUMN role ENUM('user','assistant','advisor','system') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE whatsapp_messages MODIFY COLUMN role ENUM('user','assistant') NOT NULL");
    }
};
