<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_origen_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('branch_destino_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('completado'); // completado, cancelado
            $table->text('notas')->nullable();
            $table->date('fecha');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('branch_transfer_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_transfer_id')->constrained('branch_transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('quantity');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_transfer_details');
        Schema::dropIfExists('branch_transfers');
    }
};
