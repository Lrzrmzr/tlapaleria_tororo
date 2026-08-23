<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * gastos.monto y sale_details.total quedaron como `float` desde su creación
 * (nunca se migraron a decimal como el resto de columnas monetarias del
 * sistema). float usa punto flotante binario y puede acumular errores de
 * redondeo en sumas de dinero; decimal(10,2) es exacto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gastos', function (Blueprint $table) {
            $table->decimal('monto', 10, 2)->default(0)->change();
        });

        Schema::table('sale_details', function (Blueprint $table) {
            $table->decimal('total', 10, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('gastos', function (Blueprint $table) {
            $table->float('monto')->default(0)->change();
        });

        Schema::table('sale_details', function (Blueprint $table) {
            $table->float('total')->default(0)->change();
        });
    }
};
