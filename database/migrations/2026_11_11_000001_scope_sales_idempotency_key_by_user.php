<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La clave de idempotencia la genera el cliente: debe ser única por usuario,
 * no global. Con el índice global, dos usuarios con la misma clave chocaban y
 * el segundo recibía la venta del primero.
 *
 * Las claves existentes ya eran únicas globalmente, así que también lo son
 * por (user_id, idempotency_key) y la migración no puede fallar por datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->unique(['user_id', 'idempotency_key'], 'sales_user_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        // Revertir puede fallar si ya existen claves repetidas entre usuarios.
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique('sales_user_idempotency_key_unique');
            $table->unique('idempotency_key');
        });
    }
};
