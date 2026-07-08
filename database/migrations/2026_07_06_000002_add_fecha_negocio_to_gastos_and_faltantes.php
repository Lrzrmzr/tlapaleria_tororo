<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gastos', function (Blueprint $table) {
            $table->date('fecha_gasto')->nullable()->after('descripcion');
        });
        DB::table('gastos')->whereNull('fecha_gasto')->update(['fecha_gasto' => DB::raw('DATE(created_at)')]);

        Schema::table('faltantes', function (Blueprint $table) {
            $table->date('fecha_faltante')->nullable()->after('descripcion');
        });
        DB::table('faltantes')->whereNull('fecha_faltante')->update(['fecha_faltante' => DB::raw('DATE(created_at)')]);
    }

    public function down(): void
    {
        Schema::table('gastos', function (Blueprint $table) {
            $table->dropColumn('fecha_gasto');
        });
        Schema::table('faltantes', function (Blueprint $table) {
            $table->dropColumn('fecha_faltante');
        });
    }
};
