<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mermas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('quantity');
            $table->string('motivo', 30); // dañado, caducado, robo, extravio, otro
            $table->text('notas')->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('fecha');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mermas');
    }
};
