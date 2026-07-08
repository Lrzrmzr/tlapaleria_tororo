<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->insertOrIgnore([
            'key' => 'stock_critico_threshold',
            'value' => '2',
            'type' => 'integer',
            'description' => 'Cantidad de stock en o por debajo de la cual un producto se considera crítico',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'stock_critico_threshold')->delete();
    }
};
