<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * tour_date se creó como columna `date`, pero el cliente casi nunca da una
 * fecha exacta y única ("después del 15 de agosto", "en dos semanas") — un
 * tipo `date` no puede representar eso. Se convierte a texto libre para
 * poder guardar tanto fechas exactas como referencias aproximadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('leads', 'tour_date')) {
            DB::statement('ALTER TABLE leads MODIFY tour_date VARCHAR(100) NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('leads', 'tour_date')) {
            DB::statement('ALTER TABLE leads MODIFY tour_date DATE NULL');
        }
    }
};
