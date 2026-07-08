<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('tipo_pago', 20)->default('contado')->after('status');
            $table->string('numero_factura', 100)->nullable()->after('tipo_pago');
            $table->string('numero_pedido', 100)->nullable()->after('numero_factura');
            $table->date('fecha_factura')->nullable()->after('numero_pedido');
            $table->date('fecha_vencimiento')->nullable()->after('fecha_factura');
            $table->text('notas')->nullable()->after('fecha_vencimiento');
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['tipo_pago', 'numero_factura', 'numero_pedido', 'fecha_factura', 'fecha_vencimiento', 'notas']);
        });
    }
};
