<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Equivalente JSON de CheckBranchAccess para las rutas auth:api.
 *
 * Auditoría run-1: el grupo auth:api no pasaba por check.branch, así que un
 * usuario desactivado seguía usando su token (6 meses) y, con
 * MOBILE_API_ENABLED, un cliente o un usuario no-root sin sucursal caía en la
 * rama "sin filtro" de los index (InventarioApi/GastoApi/FaltanteApi) y podía
 * elegir cualquier branch_id.
 *
 * Uso: 'api.access' (solo cuenta activa) o 'api.access:branch' (además exige
 * rol con acceso al sistema y sucursal asignada salvo root).
 */
class EnsureApiUserAccess
{
    public function handle(Request $request, Closure $next, string $mode = 'active'): Response
    {
        $user = $request->user();

        if (!$user) {
            return $next($request); // auth:api responde 401
        }

        if (!$user->active) {
            return response()->json([
                'success' => false,
                'message' => 'Tu cuenta ha sido desactivada. Contacta al administrador.',
            ], 403);
        }

        if ($mode === 'branch') {
            if ($user->isClient() || !$user->hasSystemAccess()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes acceso a este recurso.',
                ], 403);
            }
        }

        return $next($request);
    }
}
