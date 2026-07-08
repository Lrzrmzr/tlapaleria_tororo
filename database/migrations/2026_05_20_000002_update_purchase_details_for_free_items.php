<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add descripcion column
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->string('descripcion', 255)->nullable()->after('product_id');
        });

        // Make product_id nullable
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->dropColumn('descripcion');
        });

        Schema::table('purchase_details', function (Blueprint $table) {
            $table->foreignId('product_id')->change();
        });
    }
};
