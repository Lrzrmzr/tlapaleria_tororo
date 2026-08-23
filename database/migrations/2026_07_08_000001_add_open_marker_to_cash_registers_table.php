<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La migración 2026_07_07_000001 protege contra doble apertura de caja con un
 * índice único parcial, pero solo se crea en PostgreSQL. Esta columna reproduce
 * la misma garantía en cualquier motor: NULL en filas cerradas (todas las bases
 * de datos permiten múltiples NULL en un índice único), branch_id en la fila
 * abierta. CashRegister::booted() la mantiene sincronizada con `status`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_registers', function (Blueprint $table) {
            $table->unsignedBigInteger('open_marker')->nullable()->after('branch_id');
        });

        DB::table('cash_registers')
            ->where('status', 'abierta')
            ->whereNull('deleted_at')
            ->update(['open_marker' => DB::raw('branch_id')]);

        Schema::table('cash_registers', function (Blueprint $table) {
            $table->unique('open_marker', 'cash_registers_open_marker_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cash_registers', function (Blueprint $table) {
            $table->dropUnique('cash_registers_open_marker_unique');
            $table->dropColumn('open_marker');
        });
    }
};
