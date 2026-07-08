<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('tipo', 20); // entrada, salida, ajuste, merma, traspaso_salida, traspaso_entrada, devolucion
            $table->integer('cantidad');
            $table->integer('stock_antes');
            $table->integer('stock_despues');
            $table->string('motivo', 255)->nullable();
            $table->string('origen_type', 100)->nullable();
            $table->unsignedBigInteger('origen_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'product_id']);
            $table->index(['origen_type', 'origen_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
