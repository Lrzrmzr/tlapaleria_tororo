<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductApiController;
use App\Http\Controllers\Api\InventarioApiController;
use App\Http\Controllers\Api\GastoApiController;
use App\Http\Controllers\Api\FaltanteApiController;
use App\Http\Controllers\Api\BranchApiController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\FaltanteController;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\VentaController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ========================================
// ENDPOINTS PÚBLICOS (sin autenticación)
// ========================================

// Endpoint público — throttle:60,1 = 60 req/min por IP para prevenir scraping
Route::get('/products', [PublicController::class, 'apiProducts'])->middleware('throttle:60,1');

// Autenticación - Login (con middleware web para crear sesión)
// El middleware 'web' permite crear sesión para la app web
// y también retorna token para la app móvil
// throttle:10,1 = máximo 10 intentos por minuto por IP
Route::middleware(['web', 'throttle:10,1'])->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
});

// ========================================
// ENDPOINTS PROTEGIDOS (requieren autenticación OAuth 2.0)
// ========================================

Route::middleware(['auth:api'])->group(function () {

    // Autenticación - Información del usuario y logout
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);
    Route::get('/refresh-info', [AuthController::class, 'refresh']);

    // Información del usuario autenticado (endpoint alternativo)
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // ========================================
    // ENDPOINTS PARA APP MÓVIL
    // App Capacitor/Android descartada por ahora — con MOBILE_API_ENABLED=false
    // (default) ninguna de estas rutas /mobile/* se registra.
    // ========================================

    if (env('MOBILE_API_ENABLED', false)) {
        // Productos
        Route::get('/mobile/products', [ProductApiController::class, 'index']);
        Route::get('/mobile/products/{id}', [ProductApiController::class, 'show']);
        Route::post('/mobile/products', [ProductApiController::class, 'store']);
        Route::put('/mobile/products/{id}', [ProductApiController::class, 'update']);
        Route::delete('/mobile/products/{id}', [ProductApiController::class, 'destroy']);
        Route::get('/mobile/products/barcode/{barcode}', [ProductApiController::class, 'searchByBarcode']);

        // Inventario
        Route::get('/mobile/inventory', [InventarioApiController::class, 'index']);
        Route::get('/mobile/inventory/{id}', [InventarioApiController::class, 'show']);
        Route::post('/mobile/inventory', [InventarioApiController::class, 'store']);
        Route::post('/mobile/inventory/{id}/adjust', [InventarioApiController::class, 'adjustStock']);
        Route::get('/mobile/inventory/productos-sin-inventario', [InventarioApiController::class, 'productosSinInventario']);

        // Gastos
        Route::get('/mobile/gastos', [GastoApiController::class, 'index']);
        Route::get('/mobile/gastos/{id}', [GastoApiController::class, 'show']);
        Route::post('/mobile/gastos', [GastoApiController::class, 'store']);
        Route::put('/mobile/gastos/{id}', [GastoApiController::class, 'update']);
        Route::delete('/mobile/gastos/{id}', [GastoApiController::class, 'destroy']);

        // Faltantes
        Route::get('/mobile/faltantes', [FaltanteApiController::class, 'index']);
        Route::get('/mobile/faltantes/{id}', [FaltanteApiController::class, 'show']);
        Route::post('/mobile/faltantes', [FaltanteApiController::class, 'store']);
        Route::put('/mobile/faltantes/{id}', [FaltanteApiController::class, 'update']);
        Route::post('/mobile/faltantes/{id}/complete', [FaltanteApiController::class, 'markAsCompleted']);
        Route::delete('/mobile/faltantes/{id}', [FaltanteApiController::class, 'destroy']);

        // Sucursales
        Route::get('/mobile/branches', [BranchApiController::class, 'index']);
        Route::get('/mobile/branches/{id}', [BranchApiController::class, 'show']);
        Route::get('/mobile/branches/{id}/stats', [BranchApiController::class, 'stats']);
    }

    // ========================================
    // ENDPOINTS ADMINISTRATIVOS (solo root, administradores y trabajadores)
    // ========================================

    // Rutas admin/* eliminadas: usaban controladores web (Inertia) que dependen del
    // middleware check.branch no disponible en contexto API, lo que causaba branch_id=null
    // en escrituras y filtraciones de datos entre sucursales. Usar los endpoints mobile/* equivalentes.
});
