<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * En PostgreSQL una FK (foreignId()->constrained()) NO crea índice
     * automáticamente (a diferencia de MySQL) — sale_details.sale_id/product_id
     * quedaban sin índice real pese a usarse en joins de reportes. Las columnas
     * de "fecha de negocio" (fecha_venta/fecha_gasto), que son el filtro de
     * todos los reportes, tampoco tenían índice al agregarse después del
     * primer barrido de optimización.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->index('fecha_venta', 'idx_sales_fecha_venta');
            $table->index(['branch_id', 'fecha_venta'], 'idx_sales_branch_fecha_venta');
        });

        Schema::table('gastos', function (Blueprint $table) {
            $table->index('fecha_gasto', 'idx_gastos_fecha_gasto');
            $table->index(['branch_id', 'fecha_gasto'], 'idx_gastos_branch_fecha_gasto');
        });

        Schema::table('sale_details', function (Blueprint $table) {
            $table->index('sale_id', 'idx_sale_details_sale_id');
            $table->index('product_id', 'idx_sale_details_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('idx_sales_fecha_venta');
            $table->dropIndex('idx_sales_branch_fecha_venta');
        });

        Schema::table('gastos', function (Blueprint $table) {
            $table->dropIndex('idx_gastos_fecha_gasto');
            $table->dropIndex('idx_gastos_branch_fecha_gasto');
        });

        Schema::table('sale_details', function (Blueprint $table) {
            $table->dropIndex('idx_sale_details_sale_id');
            $table->dropIndex('idx_sale_details_product_id');
        });
    }
};
