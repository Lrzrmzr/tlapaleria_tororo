<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // cajero que abrió
            $table->decimal('fondo_inicial', 10, 2)->default(0);
            $table->timestamp('fecha_apertura');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fecha_cierre')->nullable();
            $table->decimal('ventas_efectivo', 10, 2)->nullable();
            $table->decimal('gastos_periodo', 10, 2)->nullable();
            $table->decimal('cobros_efectivo', 10, 2)->nullable();
            $table->decimal('pagos_efectivo', 10, 2)->nullable();
            $table->decimal('efectivo_esperado', 10, 2)->nullable();
            $table->decimal('efectivo_contado', 10, 2)->nullable();
            $table->decimal('diferencia', 10, 2)->nullable();
            $table->string('status', 20)->default('abierta'); // abierta, cerrada
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_registers');
    }
};
