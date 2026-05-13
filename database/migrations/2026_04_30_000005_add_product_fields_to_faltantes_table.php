<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('faltantes', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete()->after('user_id');
            $table->unsignedInteger('quantity')->nullable()->after('product_id');
            $table->text('notes')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('faltantes', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropColumn(['product_id', 'quantity', 'notes']);
        });
    }
};
