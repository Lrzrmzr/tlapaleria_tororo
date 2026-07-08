<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->date('fecha_venta')->nullable()->after('branch_id');
            $table->string('metodo_pago', 20)->default('efectivo')->after('status');
            $table->string('canal', 20)->default('pos')->after('metodo_pago');
            $table->foreignId('cancelled_by')->nullable()->after('canal')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->string('cancel_reason', 255)->nullable()->after('cancelled_at');
        });

        DB::table('sales')->whereNull('fecha_venta')->update(['fecha_venta' => DB::raw('DATE(created_at)')]);

        Schema::table('sale_details', function (Blueprint $table) {
            $table->decimal('cost', 10, 2)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('sale_details', function (Blueprint $table) {
            $table->dropColumn('cost');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['fecha_venta', 'metodo_pago', 'canal', 'cancelled_at', 'cancel_reason']);
        });
    }
};
